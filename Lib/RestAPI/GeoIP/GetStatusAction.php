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
use Modules\ModuleGeoIP\bin\WorkerGeoIPUpdater;
use Modules\ModuleGeoIP\Lib\GeoIPSetManager;
use Modules\ModuleGeoIP\Models\GeoFilterCountries;
use Modules\ModuleGeoIP\Models\ModuleGeoIP;
use Phalcon\Di\Di;

/**
 * GET /geoip:status — module status with ipset statistics.
 */
class GetStatusAction
{
    public static function main(array $data): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->processor = __METHOD__;

        try {
            $settings = ModuleGeoIP::findFirst();
            $lastUpdate = $settings !== null ? ($settings->lastUpdate ?? '') : '';

            $blockedCountries = GeoFilterCountries::count([
                'conditions' => 'blocked = :blocked:',
                'bind'       => ['blocked' => '1'],
            ]);

            $ipsetStats = GeoIPSetManager::getStats();

            // Get download progress and update-requested flag from cache
            $progress = -1;
            $updateRequested = false;
            try {
                $di = Di::getDefault();
                if ($di !== null && $di->has('managedCache')) {
                    $cache = $di->getShared('managedCache');
                    $p = $cache->get('GeoIP:progress');
                    if ($p !== null) {
                        $progress = (int)$p;
                    }
                    $updateRequested = (bool)$cache->get('GeoIP:updateRequested');
                }
            } catch (\Throwable $e) {
                // ignore
            }

            $result->success = true;
            $result->data = [
                'lastUpdate'       => $lastUpdate,
                'blockedCountries' => (int)$blockedCountries,
                'blockedCidrsV4'   => $ipsetStats['v4_count'],
                'blockedCidrsV6'   => $ipsetStats['v6_count'],
                'ipsetAvailable'   => $ipsetStats['available'],
                'progress'         => $progress,
                'updateRequested'  => $updateRequested,
                'isRunning'        => WorkerGeoIPUpdater::isAlreadyRunning(),
            ];
        } catch (\Throwable $e) {
            \MikoPBX\Core\System\Util::sysLogMsg(__CLASS__, 'Failed to get status: ' . $e->getMessage());
            $result->success = false;
            $result->messages[] = 'Failed to get status';
        }

        return $result;
    }
}
