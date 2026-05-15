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

namespace Modules\ModuleGeoIP\Lib;

use GuzzleHttp\Client;
use MikoPBX\Core\System\Util;

/**
 * Downloads DB-IP Lite country CSV and converts IP ranges to per-country CIDR zone files.
 *
 * DB-IP Lite CSV format: start_ip,end_ip,country_code
 * Free, updated monthly, CC BY 4.0 license, no registration required.
 * Has sub-allocation granularity (resolves IPs within large blocks to actual countries).
 *
 * Source selection order:
 *   1. Bundled offline copy at <module>/db/dbip-country-lite.csv.gz (refreshed at build time).
 *   2. Live download from download.db-ip.com — fallback only when the bundled file is missing.
 *
 * Streaming implementation: gzipped CSV is read line-by-line through gzopen/gzgets so peak
 * memory stays O(output size) instead of O(raw CSV size).
 */
class DBIPDataProvider
{
    private const CSV_URL_TEMPLATE = 'https://download.db-ip.com/free/dbip-country-lite-%s.csv.gz';
    private const HTTP_TIMEOUT     = 120;
    private const MIN_COMPRESSED   = 1 * 1024 * 1024;   // 1 MB lower bound (real file is ~4 MB)
    private const MAX_COMPRESSED   = 50 * 1024 * 1024;  // 50 MB upper bound
    private const MAX_LINES        = 5_000_000;         // hard safety cap on CSV rows
    private const OFFLINE_FILE     = 'dbip-country-lite.csv.gz';
    private const STALE_WARN_DAYS  = 90;                // log warning when bundled file is older than this

    /**
     * Path to the bundled offline DB-IP Lite copy shipped with the module.
     */
    public static function getOfflineDbPath(): string
    {
        return dirname(__DIR__) . '/db/' . self::OFFLINE_FILE;
    }

    /**
     * Build zone files from DB-IP Lite data.
     *
     * Prefers the bundled offline copy; falls back to network download if it's missing.
     *
     * @param string $dataDir Directory to write zone files
     * @param callable|null $progressCallback fn(int $percent)
     * @param callable|null $interruptCheck fn(): bool — return true to abort
     */
    public static function downloadAndBuild(
        string    $dataDir,
        ?callable $progressCallback = null,
        ?callable $interruptCheck = null
    ): void {
        if ($progressCallback !== null) {
            $progressCallback(5);
        }

        $offlinePath = self::getOfflineDbPath();
        $sourcePath  = null;
        $cleanupTmp  = false;

        if (is_file($offlinePath) && filesize($offlinePath) > 0) {
            self::warnIfStale($offlinePath);
            Util::sysLogMsg(__CLASS__, "Using bundled offline DB-IP CSV: $offlinePath");
            $sourcePath = $offlinePath;
        } else {
            Util::sysLogMsg(__CLASS__, 'Offline DB-IP CSV not bundled, falling back to live download');
            $sourcePath = self::downloadFreshCopy($interruptCheck);
            if ($sourcePath === null) {
                Util::sysLogMsg(__CLASS__, 'Failed to obtain DB-IP CSV from any source');
                return;
            }
            $cleanupTmp = true;
        }

        try {
            $compressedSize = filesize($sourcePath);
            if ($compressedSize === false || $compressedSize < self::MIN_COMPRESSED) {
                Util::sysLogMsg(__CLASS__, "DB-IP CSV too small or unreadable: " . var_export($compressedSize, true));
                return;
            }
            if ($compressedSize > self::MAX_COMPRESSED) {
                Util::sysLogMsg(__CLASS__, "DB-IP CSV too large: $compressedSize bytes");
                return;
            }

            if ($progressCallback !== null) {
                $progressCallback(30);
            }

            if ($interruptCheck !== null && $interruptCheck()) {
                return;
            }

            $allocations = self::parseGzCsv($sourcePath, $progressCallback, $interruptCheck);

            if (empty($allocations)) {
                Util::sysLogMsg(__CLASS__, 'No allocations parsed from DB-IP CSV');
                return;
            }

            if ($progressCallback !== null) {
                $progressCallback(90);
            }

            self::cleanZoneFiles($dataDir);
            self::writeZoneFiles($allocations, $dataDir);

            if ($progressCallback !== null) {
                $progressCallback(100);
            }

            Util::sysLogMsg(__CLASS__, 'DB-IP data processed, ' . count($allocations) . ' countries');
        } finally {
            if ($cleanupTmp) {
                @unlink($sourcePath);
            }
        }
    }

    /**
     * Emit a syslog warning if the bundled CSV is older than STALE_WARN_DAYS.
     * Doesn't block — the package owner is expected to refresh manually.
     */
    private static function warnIfStale(string $path): void
    {
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return;
        }
        $ageDays = (int)floor((time() - $mtime) / 86400);
        if ($ageDays > self::STALE_WARN_DAYS) {
            Util::sysLogMsg(__CLASS__, "Bundled DB-IP CSV is $ageDays days old, consider updating the module package");
        }
    }

    /**
     * Download a fresh gzipped CSV to a temp file.
     *
     * @return string|null Path to temp file (caller deletes), or null on failure
     */
    private static function downloadFreshCopy(?callable $interruptCheck): ?string
    {
        $client = new Client([
            'timeout'         => self::HTTP_TIMEOUT,
            'connect_timeout' => 15,
            'verify'          => true,
        ]);

        $tmpGz = tempnam(sys_get_temp_dir(), 'dbip_');
        if ($tmpGz === false) {
            Util::sysLogMsg(__CLASS__, 'Failed to create temp file for DB-IP download');
            return null;
        }

        $url = sprintf(self::CSV_URL_TEMPLATE, date('Y-m'));
        if (!self::downloadToFile($client, $url, $tmpGz)) {
            $prevMonth = date('Y-m', strtotime('-1 month'));
            $url = sprintf(self::CSV_URL_TEMPLATE, $prevMonth);
            if (!self::downloadToFile($client, $url, $tmpGz)) {
                @unlink($tmpGz);
                return null;
            }
        }

        if ($interruptCheck !== null && $interruptCheck()) {
            @unlink($tmpGz);
            return null;
        }

        return $tmpGz;
    }

    /**
     * Download a URL directly to a file via Guzzle sink (no in-memory body buffer).
     *
     * @return bool True on HTTP 2xx
     */
    private static function downloadToFile(Client $client, string $url, string $destPath): bool
    {
        try {
            $response = $client->get($url, [
                'sink'        => $destPath,
                'http_errors' => true,
            ]);
            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Stream-parse a gzipped CSV into per-country CIDR arrays.
     *
     * @return array ['CC' => ['v4' => [...], 'v6' => [...]]]
     */
    private static function parseGzCsv(
        string    $tmpGz,
        ?callable $progressCallback,
        ?callable $interruptCheck
    ): array {
        $allocations = [];
        $fh = @gzopen($tmpGz, 'rb');
        if ($fh === false) {
            Util::sysLogMsg(__CLASS__, 'Failed to gzopen DB-IP CSV');
            return $allocations;
        }

        $idx = 0;
        while (!gzeof($fh)) {
            $line = gzgets($fh);
            if ($line === false) {
                break;
            }
            $idx++;
            if ($idx > self::MAX_LINES) {
                Util::sysLogMsg(__CLASS__, 'DB-IP CSV exceeds line cap, truncating');
                break;
            }

            if (($idx % 10000) === 0 && $interruptCheck !== null && $interruptCheck()) {
                gzclose($fh);
                return $allocations;
            }

            // Progress: 30-90% during parsing, based on decoded bytes read
            if ($progressCallback !== null && ($idx % 50000) === 0) {
                // gztell gives uncompressed offset; we don't know total, so advance slowly
                $progressCallback(min(89, 30 + (int)($idx / 50000)));
            }

            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $parts = str_getcsv($line, ',', '"', '');
            if (count($parts) < 3) {
                continue;
            }

            $startIp = $parts[0];
            $endIp   = $parts[1];
            $cc      = strtoupper($parts[2]);

            if (!preg_match('/^[A-Z]{2}$/', $cc) || $cc === 'ZZ') {
                continue;
            }

            $isV6 = str_contains($startIp, ':');

            if ($isV6) {
                $cidrs = self::rangeToCidrsV6($startIp, $endIp);
                if (!empty($cidrs)) {
                    foreach ($cidrs as $cidr) {
                        $allocations[$cc]['v6'][] = $cidr;
                    }
                }
            } else {
                $cidrs = self::rangeToCidrsV4($startIp, $endIp);
                if (!empty($cidrs)) {
                    foreach ($cidrs as $cidr) {
                        $allocations[$cc]['v4'][] = $cidr;
                    }
                }
            }
        }

        gzclose($fh);
        return $allocations;
    }

    /**
     * Convert an IPv4 range (start-end) to CIDR blocks using native 64-bit integers.
     * Roughly 10× faster and allocation-free compared to the GMP path.
     *
     * @return string[]
     */
    private static function rangeToCidrsV4(string $startIp, string $endIp): array
    {
        $start = ip2long($startIp);
        $end   = ip2long($endIp);
        if ($start === false || $end === false || $start > $end) {
            return [];
        }

        // Promote to unsigned via bitmask for arithmetic on 64-bit PHP
        $start &= 0xFFFFFFFF;
        $end   &= 0xFFFFFFFF;

        $cidrs = [];
        while ($start <= $end) {
            // Largest power-of-two block aligned at $start
            $maxSize = 32;
            if ($start !== 0) {
                // Count trailing zero bits
                $trail = 0;
                $tmp = $start;
                while (($tmp & 1) === 0 && $trail < 32) {
                    $tmp >>= 1;
                    $trail++;
                }
                $maxSize = $trail;
            }

            // Shrink block so it fits within [$start, $end]
            while ($maxSize > 0) {
                $blockSize = 1 << $maxSize;
                if (($start + $blockSize - 1) <= $end) {
                    break;
                }
                $maxSize--;
            }

            $prefix  = 32 - $maxSize;
            $cidrs[] = long2ip($start) . '/' . $prefix;

            $start += (1 << $maxSize);
        }

        return $cidrs;
    }

    /**
     * Convert an IPv6 range to CIDR blocks using GMP.
     * IPv6 ranges in DB-IP are rare; GMP overhead is acceptable here.
     *
     * @return string[]
     */
    private static function rangeToCidrsV6(string $startIp, string $endIp): array
    {
        $startPacked = @inet_pton($startIp);
        $endPacked   = @inet_pton($endIp);
        if ($startPacked === false || $endPacked === false) {
            return [];
        }
        $start = gmp_init(bin2hex($startPacked), 16);
        $end   = gmp_init(bin2hex($endPacked), 16);
        if (gmp_cmp($start, $end) > 0) {
            return [];
        }

        $cidrs = [];
        while (gmp_cmp($start, $end) <= 0) {
            $maxSize = 128;
            if (gmp_cmp($start, 0) !== 0) {
                $trail = 0;
                $tmp = $start;
                while (gmp_cmp(gmp_and($tmp, 1), 0) === 0 && $trail < 128) {
                    $tmp = gmp_div_q($tmp, 2);
                    $trail++;
                }
                $maxSize = $trail;
            }

            while ($maxSize > 0) {
                $blockEnd = gmp_add($start, gmp_sub(gmp_pow(2, $maxSize), 1));
                if (gmp_cmp($blockEnd, $end) <= 0) {
                    break;
                }
                $maxSize--;
            }

            $hex = gmp_strval($start, 16);
            $hex = str_pad($hex, 32, '0', STR_PAD_LEFT);
            $cidrs[] = inet_ntop(hex2bin($hex)) . '/' . (128 - $maxSize);

            $start = gmp_add($start, gmp_pow(2, $maxSize));
        }

        return $cidrs;
    }

    /**
     * Remove all existing zone files.
     */
    private static function cleanZoneFiles(string $dataDir): void
    {
        $files = glob($dataDir . '/*.zone');
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            unlink($file);
        }
    }

    /**
     * Write per-country zone files.
     */
    private static function writeZoneFiles(array $allocations, string $dataDir): void
    {
        foreach ($allocations as $cc => $data) {
            $ccLower = strtolower($cc);

            if (!empty($data['v4'])) {
                file_put_contents($dataDir . '/' . $ccLower . '.zone', implode("\n", $data['v4']) . "\n");
            }
            if (!empty($data['v6'])) {
                file_put_contents($dataDir . '/' . $ccLower . '-v6.zone', implode("\n", $data['v6']) . "\n");
            }
        }
    }
}
