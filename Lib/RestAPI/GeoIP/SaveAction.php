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
            $blockedCodes = $data['blocked'] ?? [];
            if (!is_array($blockedCodes)) {
                $result->success = false;
                $result->messages[] = 'Parameter "blocked" must be an array of country codes';
                return $result;
            }

            // Validate country codes
            $validCodes = array_keys(GeoIPCountryList::getAll());
            $blockedCodes = array_map('strtoupper', $blockedCodes);
            $blockedCodes = array_intersect($blockedCodes, $validCodes);

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
                    $iptablesConfClass = '\MikoPBX\Core\System\IptablesConf';
                    if (class_exists($iptablesConfClass) && method_exists($iptablesConfClass, 'reloadFirewall')) {
                        $iptablesConfClass::reloadFirewall();
                    }
                }
            }

            $result->success = true;
            $result->data = [
                'blockedCount' => count($blockedCodes),
            ];
        } catch (\Throwable $e) {
            $result->success = false;
            $result->messages[] = 'Failed to save countries: ' . $e->getMessage();
        }

        return $result;
    }
}
