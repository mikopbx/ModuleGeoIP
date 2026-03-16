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

use MikoPBX\Common\Providers\ManagedCacheProvider;
use MikoPBX\Core\System\Util;
use Phalcon\Di\Di;

/**
 * Determines country by IP address using downloaded CIDR zone files.
 */
class GeoIPCountryLookup
{
    private const CACHE_PREFIX = 'GeoIP:lookup:';
    private const CACHE_TTL    = 3600; // 1 hour

    /**
     * Lookup country code for a given IP address.
     *
     * Priority: checks 29 languages from LanguageProvider first (most common),
     * then remaining countries.
     * Results are cached in managed cache for 1 hour.
     *
     * @param string $ip IP address to lookup
     * @return string|null ISO 3166-1 alpha-2 country code, or null if not found
     */
    public static function lookupCountry(string $ip): ?string
    {
        if (empty($ip) || $ip === '127.0.0.1' || $ip === '::1') {
            return null;
        }

        // Check cache first
        $cache = self::getCache();
        if ($cache !== null) {
            $cached = $cache->get(self::CACHE_PREFIX . $ip);
            if ($cached !== null) {
                return $cached ?: null;
            }
        }

        $dataDir = self::getDataDir();
        if (!is_dir($dataDir)) {
            return null;
        }

        $isIPv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $suffix = $isIPv6 ? '-v6.zone' : '.zone';

        // Check prioritized countries first
        $prioritized = GeoIPCountryList::getPrioritizedCodes();
        $allCodes    = array_keys(GeoIPCountryList::getAll());

        // Prioritized first, then the rest
        $orderedCodes = array_merge(
            $prioritized,
            array_diff($allCodes, $prioritized)
        );

        $result = null;
        foreach ($orderedCodes as $cc) {
            $file = $dataDir . '/' . strtolower($cc) . $suffix;
            if (!file_exists($file)) {
                continue;
            }

            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $cidr) {
                $cidr = trim($cidr);
                if ($cidr === '' || $cidr[0] === '#') {
                    continue;
                }
                if (self::isIpInCidr($ip, $cidr)) {
                    $result = strtoupper($cc);
                    break 2;
                }
            }
        }

        // Cache result (empty string for "not found" to avoid re-scanning)
        if ($cache !== null) {
            $cache->set(self::CACHE_PREFIX . $ip, $result ?? '', self::CACHE_TTL);
        }

        return $result;
    }

    /**
     * Check if an IP address belongs to a CIDR range.
     *
     * @param string $ip IP address
     * @param string $cidr CIDR notation (e.g., "192.168.1.0/24")
     * @return bool
     */
    private static function isIpInCidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int)$bits;

        $ipBin    = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        // Build bitmask
        $byteLen  = strlen($ipBin);
        $mask     = str_repeat("\xff", (int)($bits / 8));
        $leftover = $bits % 8;
        if ($leftover > 0) {
            $mask .= chr(0xff << (8 - $leftover) & 0xff);
        }
        $mask = str_pad($mask, $byteLen, "\x00");

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }

    /**
     * Get the directory where CIDR zone files are stored.
     */
    public static function getDataDir(): string
    {
        return '/storage/usbdisk1/mikopbx/tmp/geoip';
    }

    /**
     * Get the managed cache instance.
     */
    private static function getCache(): ?ManagedCacheProvider
    {
        try {
            $di = Di::getDefault();
            if ($di !== null && $di->has('managedCache')) {
                return $di->getShared('managedCache');
            }
        } catch (\Throwable $e) {
            // Cache unavailable, proceed without
        }
        return null;
    }
}
