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

use MikoPBX\Common\Providers\ManagedCacheProvider;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Phalcon\Di\Di;

/**
 * POST /geoip:updateNow — reset worker cache to trigger immediate CIDR update.
 */
class UpdateNowAction
{
    public static function main(array $data): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->processor = __METHOD__;

        try {
            // Reset worker cache to force immediate check
            $di = Di::getDefault();
            if ($di !== null && $di->has('managedCache')) {
                /** @var ManagedCacheProvider $cache */
                $cache = $di->getShared('managedCache');
                $cache->delete('GeoIP:lastCheck');
                $cache->set('GeoIP:updateRequested', true, 600);
            }

            $result->success = true;
            $result->data = ['message' => 'Update scheduled'];
        } catch (\Throwable $e) {
            $result->success = false;
            $result->messages[] = 'Failed to schedule update: ' . $e->getMessage();
        }

        return $result;
    }
}
