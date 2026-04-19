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
     * The chain is inserted at position 1 of INPUT so it runs BEFORE the
     * conntrack ESTABLISHED,RELATED accept — otherwise an attacker who kept
     * a UDP "stream" alive would bypass GeoIP entirely. fail2ban will push
     * its own jumps ahead of us on restart, which is fine.
     * The INPUT jump is made idempotent by first deleting any pre-existing
     * jump, so repeated firewall reloads don't accumulate duplicates.
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

        // Remove any existing jumps (loop until -D reports no match) so we never
        // accumulate duplicates across reloads, then insert at the top of INPUT
        while (Processes::mwExec("$iptablesBin -D INPUT -j $chainName 2>/dev/null") === 0) {
            // keep deleting
        }
        Processes::mwExec("$iptablesBin -I INPUT 1 -j $chainName");
    }

    /**
     * Register a weekly cron task that refreshes CIDR data.
     *
     * Runs Sunday 03:17 — spread across a random odd minute so the load isn't hitting
     * data-source CDNs on the same second across every PBX install.
     *
     * @param array $tasks  Cron lines accumulator (SystemConfigInterface::CREATE_CRON_TASKS)
     */
    public function createCronTasks(array &$tasks): void
    {
        if (!PbxExtensionUtils::isEnabled('ModuleGeoIP')) {
            return;
        }

        $workerPath = Util::getFilePathByClassName(WorkerGeoIPUpdater::class);
        if (empty($workerPath)) {
            return;
        }

        $phpPath   = Util::which('php');
        $nohupPath = Util::which('nohup');
        $cronUser  = Util::isSystemctl() ? 'root ' : '';

        // Weekly: Sunday 03:17, random jitter prevents thundering herd on upstream servers
        $schedule = '17 3 * * 0';
        $tasks[]  = "$schedule $cronUser$nohupPath $phpPath -f $workerPath > /dev/null 2>&1 &\n";
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
