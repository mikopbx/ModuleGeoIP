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
 * Downloads and parses RIR delegation files (RIPE, ARIN, APNIC, LACNIC, AFRINIC)
 * into per-country CIDR zone files compatible with ipset.
 *
 * RIR delegation format:
 *   registry|CC|type|start|value|date|status[|extensions]
 *   Example: ripencc|RU|ipv4|31.148.136.0|1024|20161115|assigned
 *   Where value = number of addresses (IPv4) or prefix length (IPv6)
 *
 * Streaming implementation: each file is downloaded to a temp path via Guzzle sink
 * and parsed line-by-line through fgets, so a 100 MB RIR file never lives in memory.
 */
class RIRDataProvider
{
    private const RIR_URLS = [
        'https://ftp.ripe.net/pub/stats/ripencc/delegated-ripencc-extended-latest',
        'https://ftp.arin.net/pub/stats/arin/delegated-arin-extended-latest',
        'https://ftp.apnic.net/pub/stats/apnic/delegated-apnic-extended-latest',
        'https://ftp.lacnic.net/pub/stats/lacnic/delegated-lacnic-extended-latest',
        'https://ftp.afrinic.net/pub/stats/afrinic/delegated-afrinic-extended-latest',
    ];

    private const HTTP_TIMEOUT  = 60;
    private const MAX_FILE_SIZE = 100 * 1024 * 1024; // 100 MB

    /**
     * Download all RIR delegation files, parse them, and write per-country zone files.
     *
     * @param string $dataDir Directory to write zone files
     * @param callable|null $progressCallback fn(int $percent) called with progress updates
     * @param callable|null $interruptCheck fn(): bool — return true to abort
     */
    public static function downloadAndBuild(
        string   $dataDir,
        ?callable $progressCallback = null,
        ?callable $interruptCheck = null
    ): void {
        $client = new Client([
            'timeout'         => self::HTTP_TIMEOUT,
            'connect_timeout' => 15,
            'verify'          => true,
        ]);

        // Collect all allocations: ['CC' => ['v4' => [...cidrs], 'v6' => [...cidrs]]]
        $allocations = [];
        $totalRirs   = count(self::RIR_URLS);

        foreach (self::RIR_URLS as $idx => $url) {
            if ($interruptCheck !== null && $interruptCheck()) {
                return;
            }

            $percent = (int)round(($idx / $totalRirs) * 80);
            if ($progressCallback !== null) {
                $progressCallback($percent);
            }

            $tmpFile = tempnam(sys_get_temp_dir(), 'rir_');
            if ($tmpFile === false) {
                Util::sysLogMsg(__CLASS__, 'Failed to create temp file for ' . $url);
                continue;
            }

            try {
                $client->get($url, [
                    'sink'        => $tmpFile,
                    'http_errors' => true,
                ]);

                $size = @filesize($tmpFile);
                if ($size === false || $size === 0) {
                    Util::sysLogMsg(__CLASS__, "Empty RIR file: $url");
                    continue;
                }
                if ($size > self::MAX_FILE_SIZE) {
                    Util::sysLogMsg(__CLASS__, "RIR file too large: $url ($size bytes)");
                    continue;
                }

                self::parseDelegationFile($tmpFile, $allocations, $interruptCheck);
            } catch (\Throwable $e) {
                Util::sysLogMsg(__CLASS__, "Failed to download RIR file $url: " . $e->getMessage());
            } finally {
                @unlink($tmpFile);
            }
        }

        // Write zone files
        if ($progressCallback !== null) {
            $progressCallback(85);
        }

        // Remove old zone files before writing new ones
        self::cleanZoneFiles($dataDir);
        self::writeZoneFiles($allocations, $dataDir);

        if ($progressCallback !== null) {
            $progressCallback(100);
        }

        Util::sysLogMsg(__CLASS__, 'RIR delegation files processed, '
            . count($allocations) . ' countries');
    }

    /**
     * Parse a single RIR delegation file from disk, streaming line-by-line.
     */
    private static function parseDelegationFile(string $path, array &$allocations, ?callable $interruptCheck): void
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return;
        }

        $idx = 0;
        while (($line = fgets($fh)) !== false) {
            $idx++;
            if (($idx % 10000) === 0 && $interruptCheck !== null && $interruptCheck()) {
                fclose($fh);
                return;
            }

            $line = trim($line);
            // Skip empty, comments, headers, and summary lines
            if ($line === '' || $line[0] === '#' || str_contains($line, '|*|')) {
                continue;
            }

            $parts = explode('|', $line);
            // Minimum fields: registry|CC|type|start|value|date|status
            if (count($parts) < 7) {
                continue;
            }

            $cc    = strtoupper($parts[1]);
            $type  = $parts[2];  // ipv4 or ipv6
            $start = $parts[3];
            $value = $parts[4];

            // Validate country code
            if (!preg_match('/^[A-Z]{2}$/', $cc)) {
                continue;
            }

            if ($type === 'ipv4') {
                $cidr = self::ipv4CountToCidr($start, (int)$value);
                if ($cidr !== null) {
                    $allocations[$cc]['v4'][] = $cidr;
                }
            } elseif ($type === 'ipv6') {
                // For IPv6, value is the prefix length
                $prefix = (int)$value;
                if ($prefix >= 1 && $prefix <= 128 && filter_var($start, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $allocations[$cc]['v6'][] = "$start/$prefix";
                }
            }
        }

        fclose($fh);
    }

    /**
     * Convert IPv4 start address + count to CIDR notation.
     * RIR delegation files use count (power of 2) instead of prefix length.
     *
     * @return string|null CIDR string or null if invalid
     */
    private static function ipv4CountToCidr(string $startIp, int $count): ?string
    {
        if ($count <= 0 || !filter_var($startIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        // count must be power of 2
        $prefix = 32 - (int)round(log($count, 2));
        if ($prefix < 0 || $prefix > 32) {
            return null;
        }

        // Verify count matches prefix exactly (must be power of 2)
        if ((1 << (32 - $prefix)) !== $count) {
            return null;
        }

        return "$startIp/$prefix";
    }

    /**
     * Remove all existing zone files before writing fresh data.
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
     * Write per-country zone files from collected allocations.
     */
    private static function writeZoneFiles(array $allocations, string $dataDir): void
    {
        foreach ($allocations as $cc => $data) {
            $ccLower = strtolower($cc);

            // IPv4 zone file
            if (!empty($data['v4'])) {
                $content = implode("\n", $data['v4']) . "\n";
                file_put_contents($dataDir . '/' . $ccLower . '.zone', $content);
            }

            // IPv6 zone file
            if (!empty($data['v6'])) {
                $content = implode("\n", $data['v6']) . "\n";
                file_put_contents($dataDir . '/' . $ccLower . '-v6.zone', $content);
            }
        }
    }
}
