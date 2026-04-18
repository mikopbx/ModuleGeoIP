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

use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleGeoIP\bin\WorkerGeoIPUpdater;
use Phalcon\Di\Di;

/**
 * POST /geoip:updateNow — spawn the CIDR updater script as a detached process.
 *
 * The REST call returns immediately. Clients should poll GetStatusAction for progress.
 */
class UpdateNowAction
{
    public static function main(array $data): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->processor = __METHOD__;

        try {
            // Don't start a second updater if one is already live
            if (WorkerGeoIPUpdater::isAlreadyRunning()) {
                $result->success = true;
                $result->data = ['message' => 'Update already in progress'];
                return $result;
            }

            $workerPath = Util::getFilePathByClassName(WorkerGeoIPUpdater::class);
            if (empty($workerPath) || !file_exists($workerPath)) {
                $result->success = false;
                $result->messages[] = 'Updater script not found';
                return $result;
            }

            // Seed an initial progress value so the UI reacts immediately
            try {
                $di = Di::getDefault();
                if ($di !== null && $di->has('managedCache')) {
                    $di->getShared('managedCache')->set('GeoIP:progress', 0, 600);
                    $di->getShared('managedCache')->set('GeoIP:updateRequested', true, 600);
                }
            } catch (\Throwable $e) {
                // non-fatal
            }

            $phpPath = Util::which('php');
            $command = "$phpPath -f $workerPath";
            Processes::processWorker($command, '', WorkerGeoIPUpdater::PROC_TITLE, 'start');

            $result->success = true;
            $result->data = ['message' => 'Update started'];
        } catch (\Throwable $e) {
            Util::sysLogMsg(__CLASS__, 'Failed to launch updater: ' . $e->getMessage());
            $result->success = false;
            $result->messages[] = 'Failed to launch updater';
        }

        return $result;
    }
}
