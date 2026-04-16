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
 */
class DBIPDataProvider
{
    private const CSV_URL_TEMPLATE = 'https://download.db-ip.com/free/dbip-country-lite-%s.csv.gz';
    private const HTTP_TIMEOUT     = 120;
    private const MAX_FILE_SIZE    = 100 * 1024 * 1024; // 100 MB uncompressed

    /**
     * Download DB-IP CSV, parse IP ranges, convert to CIDR, write zone files.
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
        $client = new Client([
            'timeout'         => self::HTTP_TIMEOUT,
            'connect_timeout' => 15,
            'verify'          => true,
        ]);

        $url = sprintf(self::CSV_URL_TEMPLATE, date('Y-m'));

        if ($progressCallback !== null) {
            $progressCallback(5);
        }

        try {
            $response = $client->get($url);
            $compressed = $response->getBody()->getContents();
        } catch (\Throwable $e) {
            // Fallback to previous month if current month's file is not yet available
            $prevMonth = date('Y-m', strtotime('-1 month'));
            $url = sprintf(self::CSV_URL_TEMPLATE, $prevMonth);
            try {
                $response = $client->get($url);
                $compressed = $response->getBody()->getContents();
            } catch (\Throwable $e2) {
                Util::sysLogMsg(__CLASS__, "Failed to download DB-IP CSV: " . $e2->getMessage());
                return;
            }
        }

        if ($progressCallback !== null) {
            $progressCallback(30);
        }

        $csv = @gzdecode($compressed);
        unset($compressed);

        if ($csv === false || empty($csv)) {
            Util::sysLogMsg(__CLASS__, 'Failed to decompress DB-IP CSV');
            return;
        }

        if (strlen($csv) > self::MAX_FILE_SIZE) {
            Util::sysLogMsg(__CLASS__, 'DB-IP CSV too large: ' . strlen($csv) . ' bytes');
            return;
        }

        if ($progressCallback !== null) {
            $progressCallback(40);
        }

        if ($interruptCheck !== null && $interruptCheck()) {
            return;
        }

        // Parse CSV and collect CIDRs per country
        $allocations = self::parseCsv($csv, $progressCallback, $interruptCheck);
        unset($csv);

        if (empty($allocations)) {
            Util::sysLogMsg(__CLASS__, 'No allocations parsed from DB-IP CSV');
            return;
        }

        if ($progressCallback !== null) {
            $progressCallback(90);
        }

        // Clean old zone files and write new ones
        self::cleanZoneFiles($dataDir);
        self::writeZoneFiles($allocations, $dataDir);

        if ($progressCallback !== null) {
            $progressCallback(100);
        }

        Util::sysLogMsg(__CLASS__, 'DB-IP data processed, ' . count($allocations) . ' countries');
    }

    /**
     * Parse CSV lines into per-country CIDR arrays.
     *
     * @return array ['CC' => ['v4' => [...], 'v6' => [...]]]
     */
    private static function parseCsv(
        string    $csv,
        ?callable $progressCallback,
        ?callable $interruptCheck
    ): array {
        $allocations = [];
        $lines = explode("\n", $csv);
        $total = count($lines);

        foreach ($lines as $idx => $line) {
            if ($interruptCheck !== null && ($idx % 10000 === 0) && $interruptCheck()) {
                return $allocations;
            }

            // Progress: 40-90% during parsing
            if ($progressCallback !== null && $idx % 50000 === 0 && $total > 0) {
                $progressCallback(40 + (int)(($idx / $total) * 50));
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

            // Skip reserved/unknown
            if (!preg_match('/^[A-Z]{2}$/', $cc) || $cc === 'ZZ') {
                continue;
            }

            $isV6 = str_contains($startIp, ':');
            $key  = $isV6 ? 'v6' : 'v4';

            $cidrs = self::rangeToCidrs($startIp, $endIp, $isV6);
            foreach ($cidrs as $cidr) {
                $allocations[$cc][$key][] = $cidr;
            }
        }

        return $allocations;
    }

    /**
     * Convert an IP range (start-end) to an array of CIDR blocks.
     *
     * @return string[]
     */
    private static function rangeToCidrs(string $startIp, string $endIp, bool $isV6): array
    {
        $start = self::ipToInt($startIp, $isV6);
        $end   = self::ipToInt($endIp, $isV6);

        if ($start === false || $end === false || $start > $end) {
            return [];
        }

        $maxBits = $isV6 ? 128 : 32;
        $cidrs   = [];

        while ($start <= $end) {
            // Find the largest block starting at $start that fits within the range
            $maxSize = $maxBits;

            // Find trailing zeros in start address (alignment)
            if (gmp_cmp($start, 0) !== 0) {
                $trail = 0;
                $tmp = $start;
                while (gmp_cmp(gmp_and($tmp, gmp_init(1)), 0) === 0 && $trail < $maxBits) {
                    $tmp = gmp_div_q($tmp, 2);
                    $trail++;
                }
                $maxSize = $trail;
            }

            // Shrink block until it fits within the range
            while ($maxSize > 0) {
                $blockEnd = gmp_add($start, gmp_sub(gmp_pow(2, $maxSize), 1));
                if (gmp_cmp($blockEnd, $end) <= 0) {
                    break;
                }
                $maxSize--;
            }

            $prefix = $maxBits - $maxSize;
            $cidrs[] = self::intToIp($start, $isV6) . '/' . $prefix;

            $start = gmp_add($start, gmp_pow(2, $maxSize));
        }

        return $cidrs;
    }

    /**
     * Convert IP address to GMP integer.
     *
     * @return \GMP|false
     */
    private static function ipToInt(string $ip, bool $isV6)
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        $hex = bin2hex($packed);
        return gmp_init($hex, 16);
    }

    /**
     * Convert GMP integer back to IP address string.
     */
    private static function intToIp(\GMP $int, bool $isV6): string
    {
        $hex = gmp_strval($int, 16);
        $bytes = $isV6 ? 16 : 4;
        $hex = str_pad($hex, $bytes * 2, '0', STR_PAD_LEFT);
        $packed = hex2bin($hex);
        return inet_ntop($packed);
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
