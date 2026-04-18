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

    private const ALLOW_V4     = 'geoip_allowed_v4';
    private const ALLOW_V6     = 'geoip_allowed_v6';
    private const ALLOW_V4_TMP = 'geoip_allowed_v4_tmp';
    private const ALLOW_V6_TMP = 'geoip_allowed_v6_tmp';

    private const CHAIN_V4  = 'GEOIP_CHECK';
    private const CHAIN_V6  = 'GEOIP_CHECK_V6';

    private const MAX_ELEM   = 1500000;
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
     * Check if blocked ipset sets exist in the kernel.
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
     * Check if allowed ipset sets exist in the kernel.
     */
    public static function allowSetsExist(): bool
    {
        $ipset = Util::which('ipset');
        if (empty($ipset)) {
            return false;
        }
        $retV4 = Processes::mwExec("$ipset list " . self::ALLOW_V4 . " -name 2>/dev/null");
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
     * Rebuild ipset sets for allowed countries (whitelist).
     *
     * @param array $countryCodes Array of ISO 3166-1 alpha-2 codes to allow
     * @param string $dataDir Directory containing downloaded .zone files
     */
    public static function rebuildAllowSets(array $countryCodes, string $dataDir): void
    {
        $ipset = Util::which('ipset');
        if (empty($ipset)) {
            return;
        }

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
            self::rebuildOneSet(
                $ipset,
                self::ALLOW_V4,
                self::ALLOW_V4_TMP,
                'hash:net',
                'inet',
                $countryCodes,
                $dataDir,
                '.zone'
            );

            self::rebuildOneSet(
                $ipset,
                self::ALLOW_V6,
                self::ALLOW_V6_TMP,
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

        // Remove custom chains from INPUT and destroy them
        if (!empty($iptables)) {
            Processes::mwExec("$iptables -D INPUT -j " . self::CHAIN_V4 . " 2>/dev/null");
            Processes::mwExec("$iptables -F " . self::CHAIN_V4 . " 2>/dev/null");
            Processes::mwExec("$iptables -X " . self::CHAIN_V4 . " 2>/dev/null");
            // Legacy: remove direct DROP rule if present from older versions
            Processes::mwExec("$iptables -D INPUT -m set --match-set " . self::SET_V4 . " src -j DROP 2>/dev/null");
        }
        if (!empty($ip6tables)) {
            Processes::mwExec("$ip6tables -D INPUT -j " . self::CHAIN_V6 . " 2>/dev/null");
            Processes::mwExec("$ip6tables -F " . self::CHAIN_V6 . " 2>/dev/null");
            Processes::mwExec("$ip6tables -X " . self::CHAIN_V6 . " 2>/dev/null");
            Processes::mwExec("$ip6tables -D INPUT -m set --match-set " . self::SET_V6 . " src -j DROP 2>/dev/null");
        }

        // Destroy all ipset sets
        Processes::mwExec("$ipset destroy " . self::SET_V4 . " 2>/dev/null");
        Processes::mwExec("$ipset destroy " . self::SET_V6 . " 2>/dev/null");
        Processes::mwExec("$ipset destroy " . self::SET_V4_TMP . " 2>/dev/null");
        Processes::mwExec("$ipset destroy " . self::SET_V6_TMP . " 2>/dev/null");
        Processes::mwExec("$ipset destroy " . self::ALLOW_V4 . " 2>/dev/null");
        Processes::mwExec("$ipset destroy " . self::ALLOW_V6 . " 2>/dev/null");
        Processes::mwExec("$ipset destroy " . self::ALLOW_V4_TMP . " 2>/dev/null");
        Processes::mwExec("$ipset destroy " . self::ALLOW_V6_TMP . " 2>/dev/null");
    }

    /**
     * Get statistics about current ipset sets.
     *
     * @return array ['v4_count' => int, 'v6_count' => int, 'available' => bool]
     */
    public static function getStats(): array
    {
        $stats = [
            'available'        => self::isAvailable(),
            'v4_count'         => 0,
            'v6_count'         => 0,
            'v4_allowed_count' => 0,
            'v6_allowed_count' => 0,
        ];

        $ipset = Util::which('ipset');
        if (empty($ipset)) {
            return $stats;
        }

        $setsToCheck = [
            self::SET_V4   => 'v4_count',
            self::SET_V6   => 'v6_count',
            self::ALLOW_V4 => 'v4_allowed_count',
            self::ALLOW_V6 => 'v6_allowed_count',
        ];

        foreach ($setsToCheck as $setName => $statKey) {
            $output = [];
            Processes::mwExec("$ipset list $setName -t 2>/dev/null", $output);
            foreach ($output as $line) {
                if (preg_match('/Number of entries:\s*(\d+)/', $line, $m)) {
                    $stats[$statKey] = (int)$m[1];
                }
            }
        }

        return $stats;
    }

    /**
     * Get constant values for use in iptables rule injection.
     */
    public static function getChainV4(): string  { return self::CHAIN_V4; }
    public static function getChainV6(): string  { return self::CHAIN_V6; }
    public static function getSetV4(): string    { return self::SET_V4; }
    public static function getSetV6(): string    { return self::SET_V6; }
    public static function getAllowV4(): string   { return self::ALLOW_V4; }
    public static function getAllowV6(): string   { return self::ALLOW_V6; }

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

        // Stream restore data directly to a temp file — avoids O(N) memory for N CIDRs
        $tmpFile = tempnam('/tmp', 'geoip_restore_');
        if ($tmpFile === false) {
            Util::sysLogMsg(__CLASS__, 'Failed to create restore temp file');
            Processes::mwExec("$ipset destroy $tmpName 2>/dev/null");
            return;
        }

        $out = @fopen($tmpFile, 'wb');
        if ($out === false) {
            Util::sysLogMsg(__CLASS__, 'Failed to open restore temp file');
            @unlink($tmpFile);
            Processes::mwExec("$ipset destroy $tmpName 2>/dev/null");
            return;
        }

        fwrite($out, "create $tmpName $type family $family maxelem " . self::MAX_ELEM . " -exist\n");

        $cidrPattern = ($family === 'inet6')
            ? '/^[0-9a-f:]+\/\d{1,3}$/i'
            : '/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\/\d{1,2}$/';

        foreach ($countryCodes as $cc) {
            if (!preg_match('/^[A-Za-z]{2}$/', $cc)) {
                continue;
            }
            $cc   = strtolower($cc);
            $file = $dataDir . '/' . $cc . $suffix;

            $in = @fopen($file, 'rb');
            if ($in === false) {
                continue;
            }
            while (($cidr = fgets($in)) !== false) {
                $cidr = trim($cidr);
                if ($cidr === '' || $cidr[0] === '#') {
                    continue;
                }
                if (!preg_match($cidrPattern, $cidr)) {
                    Util::sysLogMsg(__CLASS__, "Invalid CIDR skipped: $cidr");
                    continue;
                }
                fwrite($out, "add $tmpName $cidr\n");
            }
            fclose($in);
        }
        fclose($out);

        Processes::mwExec("$ipset restore -f $tmpFile -exist 2>/dev/null");
        @unlink($tmpFile);

        // Create main set if it doesn't exist
        Processes::mwExec("$ipset create $setName $type family $family maxelem " . self::MAX_ELEM . " -exist");

        // Atomic swap
        Processes::mwExec("$ipset swap $tmpName $setName");
        Processes::mwExec("$ipset destroy $tmpName 2>/dev/null");
    }
}
