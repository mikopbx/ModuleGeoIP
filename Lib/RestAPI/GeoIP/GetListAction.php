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

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleGeoIP\Lib\GeoIPCountryList;
use Modules\ModuleGeoIP\Models\GeoFilterCountries;

/**
 * GET /geoip — all countries with blocked status and admin country detection.
 */
class GetListAction
{
    public static function main(array $data): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->processor = __METHOD__;

        try {
            // Build blocked countries map
            $blockedMap = [];
            $blockedRecords = GeoFilterCountries::find([
                'conditions' => 'blocked = :blocked:',
                'bind'       => ['blocked' => '1'],
            ]);
            foreach ($blockedRecords as $record) {
                $blockedMap[strtoupper($record->country_code)] = true;
            }

            // Get current language for localization
            $lang = $data['lang'] ?? 'en';

            // Build prioritized country list
            $prioritizedCodes = GeoIPCountryList::getPrioritizedCodes();
            $allCountries = GeoIPCountryList::getAll();
            $flags = GeoIPCountryList::getFlags();

            $countries = [];

            // Prioritized countries first
            foreach ($prioritizedCodes as $cc) {
                if (!isset($allCountries[$cc])) {
                    continue;
                }
                $countries[] = [
                    'code'     => $cc,
                    'name'     => GeoIPCountryList::getLocalizedName($cc, $lang),
                    'flag'     => $flags[$cc] ?? strtolower($cc),
                    'blocked'  => isset($blockedMap[$cc]),
                    'priority' => true,
                ];
            }

            // Remaining countries alphabetically
            $remaining = array_diff(array_keys($allCountries), $prioritizedCodes);
            sort($remaining);
            foreach ($remaining as $cc) {
                $countries[] = [
                    'code'     => $cc,
                    'name'     => GeoIPCountryList::getLocalizedName($cc, $lang),
                    'flag'     => $flags[$cc] ?? strtolower($cc),
                    'blocked'  => isset($blockedMap[$cc]),
                    'priority' => false,
                ];
            }

            $result->success = true;
            $result->data = [
                'countries' => $countries,
            ];
        } catch (\Throwable $e) {
            \MikoPBX\Core\System\Util::sysLogMsg(__CLASS__, 'Failed to get country list: ' . $e->getMessage());
            $result->success = false;
            $result->messages[] = 'Failed to get country list';
        }

        return $result;
    }
}
