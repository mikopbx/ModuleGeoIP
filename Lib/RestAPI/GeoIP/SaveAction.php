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

namespace Modules\ModuleGeoIP\Lib\RestAPI\GeoIP;

use MikoPBX\Core\System\Util;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleGeoIP\Lib\GeoIPCountryList;
use Modules\ModuleGeoIP\Lib\GeoIPCountryLookup;
use Modules\ModuleGeoIP\Lib\GeoIPSetManager;
use Modules\ModuleGeoIP\Models\GeoFilterCountries;
use Modules\ModuleGeoIP\Models\ModuleGeoIP;

/**
 * POST /geoip — save blocked countries list.
 */
class SaveAction
{
    public static function main(array $data): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->processor = __METHOD__;

        try {
            // Save status filter preference
            $statusFilter = $data['statusFilter'] ?? null;
            if ($statusFilter !== null && in_array($statusFilter, ['all', 'allowed', 'blocked'], true)) {
                $settings = ModuleGeoIP::findFirst();
                if ($settings !== null) {
                    $settings->statusFilter = $statusFilter;
                    $settings->save();
                }
            }

            // Save blocked countries only if explicitly provided
            if (!array_key_exists('blocked', $data)) {
                $result->success = true;
                return $result;
            }

            $blockedCodes = $data['blocked'];
            if (!is_array($blockedCodes)) {
                $result->success = false;
                $result->messages[] = 'Parameter "blocked" must be an array of country codes';
                return $result;
            }

            // Validate country codes — strict type and format check
            $validCodes = array_keys(GeoIPCountryList::getAll());
            $sanitized = [];
            foreach ($blockedCodes as $code) {
                if (is_string($code) && preg_match('/^[A-Za-z]{2}$/', $code)) {
                    $sanitized[] = strtoupper($code);
                }
            }
            $blockedCodes = array_intersect($sanitized, $validCodes);

            // Reset all to unblocked
            GeoFilterCountries::find()->filter(function ($record) {
                $record->blocked = '0';
                $record->save();
            });

            // Set blocked countries
            foreach ($blockedCodes as $cc) {
                $record = GeoFilterCountries::findFirst([
                    'conditions' => 'country_code = :cc:',
                    'bind'       => ['cc' => $cc],
                ]);
                if ($record === null) {
                    $record = new GeoFilterCountries();
                    $record->country_code = $cc;
                }
                $record->blocked = '1';
                if (!$record->save()) {
                    Util::sysLogMsg(__CLASS__, 'Failed to save country ' . $cc . ': '
                        . implode(', ', $record->getMessages()));
                }
            }

            // Rebuild ipset sets and reload firewall if module is enabled
            if (PbxExtensionUtils::isEnabled('ModuleGeoIP')) {
                $dataDir = GeoIPCountryLookup::getDataDir();
                if (is_dir($dataDir)) {
                    GeoIPSetManager::rebuildSets($blockedCodes, $dataDir);
                    // Reload firewall so onAfterIptablesReload injects DROP rules
                    $iptablesConfClass = '\MikoPBX\Core\System\Configs\IptablesConf';
                    if (class_exists($iptablesConfClass) && method_exists($iptablesConfClass, 'reloadFirewall')) {
                        $iptablesConfClass::reloadFirewall();
                    }
                }
            }

            Util::sysLogMsg(__CLASS__, 'Blocked countries updated: ' . count($blockedCodes) . ' countries');

            $result->success = true;
            $result->data = [
                'blockedCount' => count($blockedCodes),
            ];
        } catch (\Throwable $e) {
            Util::sysLogMsg(__CLASS__, 'Failed to save countries: ' . $e->getMessage());
            $result->success = false;
            $result->messages[] = 'Failed to save countries';
        }

        return $result;
    }
}
