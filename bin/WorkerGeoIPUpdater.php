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

use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
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
 * Background worker that downloads CIDR zone files and rebuilds ipset sets.
 *
 * Supports two data sources: RIR delegation files (default) and ipdeny.com.
 * Runs as PID-based worker (CHECK_BY_PID_NOT_ALERT pattern).
 * Download cycle: every 7 days + random jitter (0-3600s).
 */
class WorkerGeoIPUpdater extends WorkerBase
{
    private const CACHE_KEY      = 'GeoIP:lastCheck';
    private const CACHE_TTL      = 604800; // 7 days
    private const CACHE_JITTER   = 3600;   // 1 hour random jitter
    private const CHECK_INTERVAL = 10;     // Check every 10 seconds

    private const IPDENY_V4_URL  = 'https://www.ipdeny.com/ipblocks/data/aggregated/%s-aggregated.zone';
    private const IPDENY_V6_URL  = 'https://www.ipdeny.com/ipv6/ipaddresses/aggregated/%s-aggregated.zone';

    private const HTTP_TIMEOUT   = 30;
    private const MAX_FILE_SIZE  = 50 * 1024 * 1024; // 50 MB max per file

    /**
     * Worker entry point.
     *
     * @param array $argv Command line arguments
     */
    public function start(array $argv): void
    {
        while ($this->needRestart === false) {
            try {
                $this->checkAndUpdate();
            } catch (\Throwable $e) {
                Util::sysLogMsg(__CLASS__, 'Error: ' . $e->getMessage());
            }
            sleep(self::CHECK_INTERVAL);
        }
    }

    /**
     * Check if CIDR data needs updating and perform update if needed.
     */
    private function checkAndUpdate(): void
    {
        // Check if module is enabled (system level)
        if (!PbxExtensionUtils::isEnabled('ModuleGeoIP')) {
            return;
        }

        // Check cache to see if update is needed
        try {
            $di = Di::getDefault();
            if ($di !== null && $di->has('managedCache')) {
                $cache = $di->getShared('managedCache');
                $lastCheck = $cache->get(self::CACHE_KEY);
                if ($lastCheck !== null) {
                    return; // Not yet expired
                }
            }
        } catch (\Throwable $e) {
            // Cache unavailable, skip check
        }

        Util::sysLogMsg(__CLASS__, 'Starting CIDR data update');

        // Clear update requested flag
        try {
            $di = Di::getDefault();
            if ($di !== null && $di->has('managedCache')) {
                $mc = $di->getShared('managedCache');
                $mc->delete('GeoIP:updateRequested');
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $dataDir = GeoIPCountryLookup::getDataDir();
        Util::mwMkdir($dataDir);
        if (!is_dir($dataDir) || !is_writable($dataDir)) {
            Util::sysLogMsg(__CLASS__, "Data directory $dataDir is not writable, aborting update");
            return;
        }

        // Determine data source
        $settings = ModuleGeoIP::findFirst();
        $dataSource = ($settings !== null) ? ($settings->dataSource ?? 'dbip') : 'dbip';

        // Download zone files from chosen source
        if ($dataSource === 'ipdeny') {
            $allCodes = array_keys(GeoIPCountryList::getAll());
            $this->downloadZoneFiles($allCodes, $dataDir);
        } elseif ($dataSource === 'rir') {
            RIRDataProvider::downloadAndBuild(
                $dataDir,
                fn(int $p) => $this->updateProgress($p),
                fn()       => $this->needRestart
            );
        } else {
            DBIPDataProvider::downloadAndBuild(
                $dataDir,
                fn(int $p) => $this->updateProgress($p),
                fn()       => $this->needRestart
            );
        }

        // Rebuild ipset for blocked countries
        $blockedCodes = $this->getBlockedCodes();
        if (!empty($blockedCodes)) {
            GeoIPSetManager::rebuildSets($blockedCodes, $dataDir);

            // Rebuild ipset for allowed countries (whitelist against CIDR overlaps)
            $allowedCodes = $this->getAllowedCodes();
            if (!empty($allowedCodes)) {
                GeoIPSetManager::rebuildAllowSets($allowedCodes, $dataDir);
            }

            // Reload firewall to apply new rules
            $this->reloadFirewall();
        }

        // Update last update timestamp
        $settings = ModuleGeoIP::findFirst();
        if ($settings === null) {
            $settings = new ModuleGeoIP();
        }
        $settings->lastUpdate = date('c');
        $settings->save();

        // Clear progress, set cache with TTL + jitter
        try {
            $di = Di::getDefault();
            if ($di !== null && $di->has('managedCache')) {
                $mc = $di->getShared('managedCache');
                $mc->delete('GeoIP:progress');
                $ttl = self::CACHE_TTL + random_int(0, self::CACHE_JITTER);
                $mc->set(self::CACHE_KEY, time(), $ttl);
            }
        } catch (\Throwable $e) {
            Util::sysLogMsg(__CLASS__, 'Failed to update cache: ' . $e->getMessage());
        }

        Util::sysLogMsg(__CLASS__, 'CIDR data update completed');
    }

    /**
     * Download IPv4 and IPv6 zone files for given countries.
     *
     * @param array $countryCodes ISO 3166-1 alpha-2 codes
     * @param string $dataDir Target directory
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
            if ($this->needRestart) {
                break;
            }

            // Update progress in cache
            $progress = (int)round(($index / $total) * 100);
            $this->updateProgress($progress);

            $ccLower = strtolower($cc);

            // Download IPv4
            $this->downloadFile(
                $client,
                sprintf(self::IPDENY_V4_URL, $ccLower),
                $dataDir . '/' . $ccLower . '.zone',
                $cc
            );

            // Download IPv6
            $this->downloadFile(
                $client,
                sprintf(self::IPDENY_V6_URL, $ccLower),
                $dataDir . '/' . $ccLower . '-v6.zone',
                $cc . ' (IPv6)'
            );
        }
    }

    /**
     * Download a single zone file.
     *
     * @param Client $client HTTP client
     * @param string $url Source URL
     * @param string $destPath Destination file path
     * @param string $label Label for logging
     */
    private function downloadFile(Client $client, string $url, string $destPath, string $label): void
    {
        try {
            // Validate destination path is within expected directory
            $realDir = realpath(dirname($destPath));
            $expectedDir = realpath(GeoIPCountryLookup::getDataDir());
            if ($realDir === false || $expectedDir === false || strpos($realDir, $expectedDir) !== 0) {
                Util::sysLogMsg(__CLASS__, "Path traversal attempt for $label: $destPath");
                return;
            }

            $response = $client->get($url);
            $body = $response->getBody()->getContents();

            if (empty(trim($body))) {
                Util::sysLogMsg(__CLASS__, "Empty response for $label, skipping");
                return;
            }

            // Validate response size
            if (strlen($body) > self::MAX_FILE_SIZE) {
                Util::sysLogMsg(__CLASS__, "Response too large for $label: " . strlen($body) . " bytes");
                return;
            }

            // Validate CIDR format — each non-empty line must be a valid CIDR
            $lines = explode("\n", $body);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (!preg_match('/^[0-9a-f.:]+\/\d{1,3}$/i', $line)) {
                    Util::sysLogMsg(__CLASS__, "Invalid CIDR format in $label: $line");
                    return;
                }
            }

            file_put_contents($destPath, $body);
        } catch (\Throwable $e) {
            Util::sysLogMsg(__CLASS__, "Failed to download $label: " . $e->getMessage());
        }
    }

    /**
     * Get list of blocked country codes from DB.
     *
     * @return array
     */
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

    /**
     * Get list of allowed country codes from DB.
     *
     * @return array
     */
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

    /**
     * Trigger firewall reload.
     */
    private function reloadFirewall(): void
    {
        $iptablesConfClass = '\MikoPBX\Core\System\Configs\IptablesConf';
        if (class_exists($iptablesConfClass) && method_exists($iptablesConfClass, 'reloadFirewall')) {
            $iptablesConfClass::reloadFirewall();
        }
    }

    /**
     * Update download progress in managed cache.
     */
    private function updateProgress(int $progress): void
    {
        try {
            $di = Di::getDefault();
            if ($di !== null && $di->has('managedCache')) {
                $cache = $di->getShared('managedCache');
                $cache->set('GeoIP:progress', $progress, 600);
            }
        } catch (\Throwable $e) {
            Util::sysLogMsg(__CLASS__, 'Failed to update progress: ' . $e->getMessage());
        }
    }

}

if (isset($argv) && count($argv) !== 1
    && Util::getFilePathByClassName(WorkerGeoIPUpdater::class) === $argv[0]) {
    WorkerGeoIPUpdater::startWorker($argv ?? []);
}
