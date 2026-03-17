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

use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;

/**
 * Manages ipset sets for GeoIP blocking.
 *
 * Two sets: geoip_blocked_v4 (hash:net) and geoip_blocked_v6 (hash:net, family inet6).
 * Uses atomic swap for zero-downtime updates.
 */
class GeoIPSetManager
{
    private const SET_V4     = 'geoip_blocked_v4';
    private const SET_V6     = 'geoip_blocked_v6';
    private const SET_V4_TMP = 'geoip_blocked_v4_tmp';
    private const SET_V6_TMP = 'geoip_blocked_v6_tmp';
    private const MAX_ELEM   = 500000;
    private const LOCK_FILE  = '/tmp/geoip_rebuild.lock';

    /**
     * Check if ipset binary is available on this system.
     */
    public static function isAvailable(): bool
    {
        $ipset = Util::which('ipset');
        return !empty($ipset);
    }

    /**
     * Check if main ipset sets exist in the kernel.
     */
    public static function setsExist(): bool
    {
        $ipset = Util::which('ipset');
        if (empty($ipset)) {
            return false;
        }
        $retV4 = Processes::mwExec("$ipset list " . self::SET_V4 . " -name 2>/dev/null");
        return $retV4 === 0;
    }

    /**
     * Rebuild ipset sets from CIDR zone files for blocked countries.
     *
     * Uses atomic create-restore-swap-destroy pattern.
     *
     * @param array $countryCodes Array of ISO 3166-1 alpha-2 codes to block
     * @param string $dataDir Directory containing downloaded .zone files
     */
    public static function rebuildSets(array $countryCodes, string $dataDir): void
    {
        $ipset = Util::which('ipset');
        if (empty($ipset)) {
            return;
        }

        // Acquire exclusive lock to prevent race conditions
        $lockFp = fopen(self::LOCK_FILE, 'c');
        if ($lockFp === false) {
            Util::sysLogMsg(__CLASS__, 'Failed to open lock file');
            return;
        }
        if (!flock($lockFp, LOCK_EX)) {
            Util::sysLogMsg(__CLASS__, 'Failed to acquire rebuild lock');
            fclose($lockFp);
            return;
        }

        try {
            // Rebuild IPv4 set
            self::rebuildOneSet(
                $ipset,
                self::SET_V4,
                self::SET_V4_TMP,
                'hash:net',
                'inet',
                $countryCodes,
                $dataDir,
                '.zone'
            );

            // Rebuild IPv6 set
            self::rebuildOneSet(
                $ipset,
                self::SET_V6,
                self::SET_V6_TMP,
                'hash:net',
                'inet6',
                $countryCodes,
                $dataDir,
                '-v6.zone'
            );
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    /**
     * Destroy all GeoIP ipset sets.
     */
    public static function destroySets(): void
    {
        $ipset = Util::which('ipset');
        if (empty($ipset)) {
            return;
        }

        $iptables  = Util::which('iptables');
        $ip6tables = Util::which('ip6tables');

        // Remove iptables references first
        if (!empty($iptables)) {
            Processes::mwExec("$iptables -D INPUT -m set --match-set " . self::SET_V4 . " src -j DROP 2>/dev/null");
        }
        if (!empty($ip6tables)) {
            Processes::mwExec("$ip6tables -D INPUT -m set --match-set " . self::SET_V6 . " src -j DROP 2>/dev/null");
        }

        // Destroy sets
        Processes::mwExec("$ipset destroy " . self::SET_V4 . " 2>/dev/null");
        Processes::mwExec("$ipset destroy " . self::SET_V6 . " 2>/dev/null");
        Processes::mwExec("$ipset destroy " . self::SET_V4_TMP . " 2>/dev/null");
        Processes::mwExec("$ipset destroy " . self::SET_V6_TMP . " 2>/dev/null");
    }

    /**
     * Get statistics about current ipset sets.
     *
     * @return array ['v4_count' => int, 'v6_count' => int, 'available' => bool]
     */
    public static function getStats(): array
    {
        $stats = [
            'available' => self::isAvailable(),
            'v4_count'  => 0,
            'v6_count'  => 0,
        ];

        $ipset = Util::which('ipset');
        if (empty($ipset)) {
            return $stats;
        }

        // Parse number of entries from ipset list headers
        $output = [];
        Processes::mwExec("$ipset list " . self::SET_V4 . " -t 2>/dev/null", $output);
        foreach ($output as $line) {
            if (preg_match('/Number of entries:\s*(\d+)/', $line, $m)) {
                $stats['v4_count'] = (int)$m[1];
            }
        }

        $output = [];
        Processes::mwExec("$ipset list " . self::SET_V6 . " -t 2>/dev/null", $output);
        foreach ($output as $line) {
            if (preg_match('/Number of entries:\s*(\d+)/', $line, $m)) {
                $stats['v6_count'] = (int)$m[1];
            }
        }

        return $stats;
    }

    /**
     * Atomically rebuild a single ipset set.
     *
     * @param string $ipset Path to ipset binary
     * @param string $setName Main set name
     * @param string $tmpName Temporary set name for atomic swap
     * @param string $type Set type (hash:net)
     * @param string $family Address family (inet or inet6)
     * @param array $countryCodes Country codes to include
     * @param string $dataDir Directory with zone files
     * @param string $suffix File suffix (.zone or -v6.zone)
     */
    private static function rebuildOneSet(
        string $ipset,
        string $setName,
        string $tmpName,
        string $type,
        string $family,
        array  $countryCodes,
        string $dataDir,
        string $suffix
    ): void {
        // Create temporary set
        Processes::mwExec("$ipset destroy $tmpName 2>/dev/null");
        Processes::mwExec("$ipset create $tmpName $type family $family maxelem " . self::MAX_ELEM);

        // Build restore data from CIDR files
        $restoreData = "create $tmpName $type family $family maxelem " . self::MAX_ELEM . " -exist\n";
        $cidrPattern = ($family === 'inet6')
            ? '/^[0-9a-f:]+\/\d{1,3}$/i'
            : '/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\/\d{1,2}$/';

        foreach ($countryCodes as $cc) {
            // Validate country code format (2 alpha chars only)
            if (!preg_match('/^[A-Za-z]{2}$/', $cc)) {
                continue;
            }
            $cc = strtolower($cc);
            $file = $dataDir . '/' . $cc . $suffix;

            // Read file directly with error handling (no TOCTOU)
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $cidr) {
                $cidr = trim($cidr);
                if ($cidr === '' || $cidr[0] === '#') {
                    continue;
                }
                // Validate CIDR format before adding to ipset
                if (!preg_match($cidrPattern, $cidr)) {
                    Util::sysLogMsg(__CLASS__, "Invalid CIDR skipped: $cidr");
                    continue;
                }
                $restoreData .= "add $tmpName $cidr\n";
            }
        }

        // Bulk restore
        $tmpFile = tempnam('/tmp', 'geoip_restore_');
        file_put_contents($tmpFile, $restoreData);
        Processes::mwExec("$ipset restore -f $tmpFile -exist 2>/dev/null");
        unlink($tmpFile);

        // Create main set if it doesn't exist
        Processes::mwExec("$ipset create $setName $type family $family maxelem " . self::MAX_ELEM . " -exist");

        // Atomic swap
        Processes::mwExec("$ipset swap $tmpName $setName");
        Processes::mwExec("$ipset destroy $tmpName 2>/dev/null");
    }
}
