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
            Util::sysLogMsg(__CLASS__, 'onAfterIptablesReload: module is disabled, skipping');
            return;
        }

        if (!GeoIPSetManager::isAvailable()) {
            Util::sysLogMsg(__CLASS__, 'onAfterIptablesReload: ipset not available, skipping');
            return;
        }

        if (!GeoIPSetManager::setsExist()) {
            Util::sysLogMsg(__CLASS__, 'onAfterIptablesReload: ipset sets do not exist yet, skipping');
            return;
        }

        $hasAllowSets = GeoIPSetManager::allowSetsExist();

        // IPv4: custom chain with RETURN (allow) + DROP (block)
        $iptables = Util::which('iptables');
        if (!empty($iptables)) {
            $this->setupGeoIPChain(
                $iptables,
                GeoIPSetManager::getChainV4(),
                GeoIPSetManager::getAllowV4(),
                GeoIPSetManager::getSetV4(),
                $hasAllowSets
            );
        }

        // IPv6: custom chain with RETURN (allow) + DROP (block)
        $ip6tables = Util::which('ip6tables');
        if (!empty($ip6tables)) {
            $this->setupGeoIPChain(
                $ip6tables,
                GeoIPSetManager::getChainV6(),
                GeoIPSetManager::getAllowV6(),
                GeoIPSetManager::getSetV6(),
                $hasAllowSets
            );
        }

        Util::sysLogMsg(__CLASS__, 'onAfterIptablesReload: GeoIP chain rules added to iptables');
    }

    /**
     * Create a custom iptables chain for GeoIP filtering.
     *
     * Chain logic: allowed countries → RETURN (continue to port rules), blocked → DROP.
     */
    private function setupGeoIPChain(
        string $iptablesBin,
        string $chainName,
        string $allowSet,
        string $blockSet,
        bool   $hasAllowSets
    ): void {
        // Create chain (ignore error if already exists)
        Processes::mwExec("$iptablesBin -N $chainName 2>/dev/null");
        // Flush any old rules in the chain
        Processes::mwExec("$iptablesBin -F $chainName");

        // RETURN for allowed countries (skip DROP, continue to port-specific rules in INPUT)
        if ($hasAllowSets) {
            Processes::mwExec("$iptablesBin -A $chainName -m set --match-set $allowSet src -j RETURN");
        }

        // DROP for blocked countries
        Processes::mwExec("$iptablesBin -A $chainName -m set --match-set $blockSet src -j DROP");

        // Jump from INPUT to our chain
        Processes::mwExec("$iptablesBin -A INPUT -j $chainName");
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
            [ApiController::class, 'getListAction',    "$baseUrl/getList",    'get',  '/', true],
            [ApiController::class, 'saveAction',        "$baseUrl/save",       'post', '/', true],
            [ApiController::class, 'getStatusAction',   "$baseUrl/getStatus",  'get',  '/', true],
            [ApiController::class, 'updateNowAction',   "$baseUrl/updateNow",  'post', '/', true],
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
