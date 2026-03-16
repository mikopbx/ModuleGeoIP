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

namespace Modules\ModuleGeoIP\Lib\RestAPI;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleGeoIP\Lib\RestAPI\GeoIP\GetListAction;
use Modules\ModuleGeoIP\Lib\RestAPI\GeoIP\SaveAction;
use Modules\ModuleGeoIP\Lib\RestAPI\GeoIP\GetStatusAction;
use Modules\ModuleGeoIP\Lib\RestAPI\GeoIP\UpdateNowAction;
use Phalcon\Di\Injectable;

class GeoIPManagementProcessor extends Injectable
{
    public const ACTION_GET_LIST   = 'getList';
    public const ACTION_SAVE       = 'save';
    public const ACTION_GET_STATUS = 'getStatus';
    public const ACTION_UPDATE_NOW = 'updateNow';

    /**
     * Dispatch REST API action.
     *
     * @param string $actionName Action to execute
     * @param array $parameters Request parameters
     * @return PBXApiResult
     */
    public function callBack(string $actionName, array $parameters): PBXApiResult
    {
        switch ($actionName) {
            case self::ACTION_GET_LIST:
                return GetListAction::main($parameters);

            case self::ACTION_SAVE:
                return SaveAction::main($parameters);

            case self::ACTION_GET_STATUS:
                return GetStatusAction::main($parameters);

            case self::ACTION_UPDATE_NOW:
                return UpdateNowAction::main($parameters);

            default:
                $result = new PBXApiResult();
                $result->processor = __METHOD__;
                $result->success = false;
                $result->messages[] = "Unknown action: {$actionName}";
                return $result;
        }
    }
}
