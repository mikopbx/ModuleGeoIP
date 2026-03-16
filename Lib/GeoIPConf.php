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
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleGeoIP\bin\WorkerGeoIPUpdater;
use Modules\ModuleGeoIP\Lib\RestAPI\Controllers\ApiController;
use Modules\ModuleGeoIP\Lib\RestAPI\GeoIPManagementProcessor;

class GeoIPConf extends ConfigClass
{
    /**
     * Inject ipset-based DROP rules after core ACCEPT rules.
     * Called by Core after iptables reload.
     */
    public function onAfterIptablesReload(): void
    {
        if (!PbxExtensionUtils::isEnabled('ModuleGeoIP')) {
            return;
        }

        if (!GeoIPSetManager::isAvailable() || !GeoIPSetManager::setsExist()) {
            return;
        }

        // IPv4: drop traffic from blocked countries
        $iptables = Util::which('iptables');
        if (!empty($iptables)) {
            Processes::mwExec("$iptables -A INPUT -m set --match-set geoip_blocked_v4 src -j DROP");
        }

        // IPv6: drop traffic from blocked countries
        $ip6tables = Util::which('ip6tables');
        if (!empty($ip6tables)) {
            Processes::mwExec("$ip6tables -A INPUT -m set --match-set geoip_blocked_v6 src -j DROP");
        }
    }

    /**
     * Register the CIDR updater worker.
     */
    public function getModuleWorkers(): array
    {
        return [
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_PID_NOT_ALERT,
                'worker' => WorkerGeoIPUpdater::class,
            ],
        ];
    }

    /**
     * REST API routes for this module.
     */
    public function getPBXCoreRESTAdditionalRoutes(): array
    {
        $baseUrl = '/pbxcore/api/modules/ModuleGeoIP';
        return [
            [ApiController::class, 'getListAction',    "$baseUrl/getList",    'get',  '/', false],
            [ApiController::class, 'saveAction',        "$baseUrl/save",       'post', '/', false],
            [ApiController::class, 'getStatusAction',   "$baseUrl/getStatus",  'get',  '/', false],
            [ApiController::class, 'updateNowAction',   "$baseUrl/updateNow",  'post', '/', false],
        ];
    }

    /**
     * Handle REST API callbacks for this module.
     */
    public function moduleRestAPICallback(array $request): PBXApiResult
    {
        $action = $request['action'] ?? '';
        $data   = $request['data'] ?? [];

        $processor = new GeoIPManagementProcessor();
        return $processor->callBack($action, $data);
    }

    /**
     * Cleanup ipset sets when module is disabled.
     */
    public function onAfterModuleDisable(): void
    {
        GeoIPSetManager::destroySets();
    }
}
