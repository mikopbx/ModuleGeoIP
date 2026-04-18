<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2026 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

namespace Modules\ModuleGeoIP\bin;
require_once 'Globals.php';

use MikoPBX\Common\Handlers\CriticalErrorsHandler;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleGeoIP\Lib\GeoIPCountryList;
use Modules\ModuleGeoIP\Lib\GeoIPCountryLookup;
use Modules\ModuleGeoIP\Lib\GeoIPSetManager;
use Modules\ModuleGeoIP\Lib\DBIPDataProvider;
use Modules\ModuleGeoIP\Lib\RIRDataProvider;
use Modules\ModuleGeoIP\Models\GeoFilterCountries;
use Modules\ModuleGeoIP\Models\ModuleGeoIP;
use GuzzleHttp\Client;
use Phalcon\Di\Di;

/**
 * One-shot CIDR updater.
 *
 * Invoked directly from cron (weekly) or from the REST "updateNow" action.
 * Downloads the configured data source, rebuilds ipset sets, reloads iptables,
 * writes progress/last-update metadata and exits. A PID-based guard prevents
 * overlapping executions when an update is already running.
 */
class WorkerGeoIPUpdater
{
    public const PROC_TITLE = 'ModuleGeoIPUpdater';

    private const IPDENY_V4_URL = 'https://www.ipdeny.com/ipblocks/data/aggregated/%s-aggregated.zone';
    private const IPDENY_V6_URL = 'https://www.ipdeny.com/ipv6/ipaddresses/aggregated/%s-aggregated.zone';

    private const HTTP_TIMEOUT  = 30;
    private const MAX_FILE_SIZE = 50 * 1024 * 1024;

    private bool $interrupted = false;

    /**
     * Entry point.
     */
    public function run(): int
    {
        // Abort gracefully on SIGTERM/SIGINT
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => $this->interrupted = true);
            pcntl_signal(SIGINT,  fn() => $this->interrupted = true);
        }

        if (!PbxExtensionUtils::isEnabled('ModuleGeoIP')) {
            Util::sysLogMsg(__CLASS__, 'Module disabled, skipping update');
            return 0;
        }

        $dataDir = GeoIPCountryLookup::getDataDir();
        Util::mwMkdir($dataDir);
        if (!is_dir($dataDir) || !is_writable($dataDir)) {
            Util::sysLogMsg(__CLASS__, "Data directory $dataDir is not writable, aborting");
            return 1;
        }

        Util::sysLogMsg(__CLASS__, 'Starting CIDR data update');
        $this->clearUpdateRequestedFlag();

        $settings   = ModuleGeoIP::findFirst();
        $dataSource = ($settings !== null) ? ($settings->dataSource ?? 'dbip') : 'dbip';

        if ($dataSource === 'ipdeny') {
            $allCodes = array_keys(GeoIPCountryList::getAll());
            $this->downloadZoneFiles($allCodes, $dataDir);
        } elseif ($dataSource === 'rir') {
            RIRDataProvider::downloadAndBuild(
                $dataDir,
                fn(int $p) => $this->updateProgress($p),
                fn()       => $this->interrupted
            );
        } else {
            DBIPDataProvider::downloadAndBuild(
                $dataDir,
                fn(int $p) => $this->updateProgress($p),
                fn()       => $this->interrupted
            );
        }

        if ($this->interrupted) {
            Util::sysLogMsg(__CLASS__, 'Update interrupted');
            $this->clearProgress();
            return 130;
        }

        $blockedCodes = $this->getBlockedCodes();
        if (!empty($blockedCodes)) {
            GeoIPSetManager::rebuildSets($blockedCodes, $dataDir);

            $allowedCodes = $this->getAllowedCodes();
            if (!empty($allowedCodes)) {
                GeoIPSetManager::rebuildAllowSets($allowedCodes, $dataDir);
            }

            $this->reloadFirewall();
        }

        $settings = ModuleGeoIP::findFirst();
        if ($settings === null) {
            $settings = new ModuleGeoIP();
        }
        $settings->lastUpdate = date('c');
        $settings->save();

        $this->clearProgress();
        Util::sysLogMsg(__CLASS__, 'CIDR data update completed');
        return 0;
    }

    /**
     * Download IPv4 and IPv6 zone files for given countries (ipdeny source).
     */
    private function downloadZoneFiles(array $countryCodes, string $dataDir): void
    {
        $client = new Client([
            'timeout'         => self::HTTP_TIMEOUT,
            'connect_timeout' => 10,
            'verify'          => true,
        ]);

        $total = count($countryCodes);
        foreach ($countryCodes as $index => $cc) {
            if ($this->interrupted) {
                break;
            }

            $progress = (int)round(($index / $total) * 100);
            $this->updateProgress($progress);

            $ccLower = strtolower($cc);
            $this->downloadFile(
                $client,
                sprintf(self::IPDENY_V4_URL, $ccLower),
                $dataDir . '/' . $ccLower . '.zone',
                $cc
            );
            $this->downloadFile(
                $client,
                sprintf(self::IPDENY_V6_URL, $ccLower),
                $dataDir . '/' . $ccLower . '-v6.zone',
                $cc . ' (IPv6)'
            );
        }
    }

    /**
     * Stream a single ipdeny zone file to disk, validate line-by-line, atomic-rename.
     */
    private function downloadFile(Client $client, string $url, string $destPath, string $label): void
    {
        $realDir     = realpath(dirname($destPath));
        $expectedDir = realpath(GeoIPCountryLookup::getDataDir());
        if ($realDir === false || $expectedDir === false || strpos($realDir, $expectedDir) !== 0) {
            Util::sysLogMsg(__CLASS__, "Path traversal attempt for $label: $destPath");
            return;
        }

        $tmpPath = $destPath . '.tmp';
        try {
            $client->get($url, [
                'sink'        => $tmpPath,
                'http_errors' => true,
            ]);

            $size = @filesize($tmpPath);
            if ($size === false || $size === 0) {
                Util::sysLogMsg(__CLASS__, "Empty response for $label, skipping");
                @unlink($tmpPath);
                return;
            }
            if ($size > self::MAX_FILE_SIZE) {
                Util::sysLogMsg(__CLASS__, "Response too large for $label: $size bytes");
                @unlink($tmpPath);
                return;
            }

            $fh = @fopen($tmpPath, 'rb');
            if ($fh === false) {
                Util::sysLogMsg(__CLASS__, "Failed to open temp file for $label");
                @unlink($tmpPath);
                return;
            }
            $valid = true;
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (!preg_match('/^[0-9a-f.:]+\/\d{1,3}$/i', $line)) {
                    Util::sysLogMsg(__CLASS__, "Invalid CIDR format in $label: $line");
                    $valid = false;
                    break;
                }
            }
            fclose($fh);

            if (!$valid) {
                @unlink($tmpPath);
                return;
            }

            if (!@rename($tmpPath, $destPath)) {
                Util::sysLogMsg(__CLASS__, "Failed to rename temp file for $label");
                @unlink($tmpPath);
            }
        } catch (\Throwable $e) {
            Util::sysLogMsg(__CLASS__, "Failed to download $label: " . $e->getMessage());
            @unlink($tmpPath);
        }
    }

    private function getBlockedCodes(): array
    {
        $codes = [];
        $records = GeoFilterCountries::find([
            'conditions' => 'blocked = :blocked:',
            'bind'       => ['blocked' => '1'],
        ]);
        foreach ($records as $record) {
            $codes[] = strtoupper($record->country_code);
        }
        return $codes;
    }

    private function getAllowedCodes(): array
    {
        $codes = [];
        $records = GeoFilterCountries::find([
            'conditions' => 'blocked = :blocked:',
            'bind'       => ['blocked' => '0'],
        ]);
        foreach ($records as $record) {
            $codes[] = strtoupper($record->country_code);
        }
        return $codes;
    }

    private function reloadFirewall(): void
    {
        $iptablesConfClass = '\MikoPBX\Core\System\Configs\IptablesConf';
        if (class_exists($iptablesConfClass) && method_exists($iptablesConfClass, 'reloadFirewall')) {
            $iptablesConfClass::reloadFirewall();
        }
    }

    private function updateProgress(int $progress): void
    {
        try {
            $di = Di::getDefault();
            if ($di !== null && $di->has('managedCache')) {
                $di->getShared('managedCache')->set('GeoIP:progress', $progress, 600);
            }
        } catch (\Throwable $e) {
            Util::sysLogMsg(__CLASS__, 'Failed to update progress: ' . $e->getMessage());
        }
    }

    private function clearProgress(): void
    {
        try {
            $di = Di::getDefault();
            if ($di !== null && $di->has('managedCache')) {
                $di->getShared('managedCache')->delete('GeoIP:progress');
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function clearUpdateRequestedFlag(): void
    {
        try {
            $di = Di::getDefault();
            if ($di !== null && $di->has('managedCache')) {
                $di->getShared('managedCache')->delete('GeoIP:updateRequested');
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * Detect if another updater process is already running by looking at process titles.
     */
    public static function isAlreadyRunning(): bool
    {
        $pid = Processes::getPidOfProcess(self::PROC_TITLE, (string)getmypid());
        return !empty($pid);
    }
}

// Bootstrap: run the updater once when invoked as a CLI script.
if (isset($argv) && basename($argv[0] ?? '') === basename(__FILE__)) {
    cli_set_process_title(WorkerGeoIPUpdater::PROC_TITLE);

    if (WorkerGeoIPUpdater::isAlreadyRunning()) {
        Util::sysLogMsg(WorkerGeoIPUpdater::class, 'Another update is already running, exiting');
        exit(0);
    }

    try {
        $exitCode = (new WorkerGeoIPUpdater())->run();
        exit($exitCode);
    } catch (\Throwable $e) {
        CriticalErrorsHandler::handleExceptionWithSyslog($e);
        exit(1);
    }
}
