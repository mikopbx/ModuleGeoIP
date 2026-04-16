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

return [
    'repModuleGeoIP'                    => 'GeoIP фильтр - %repesent%',
    'mo_ModuleModuleGeoIP'              => 'GeoIP фильтр',
    'BreadcrumbModuleGeoIP'             => 'GeoIP фильтрация',
    'SubHeaderModuleGeoIP'              => 'Блокировка входящих соединений по странам на основе ipset',
    'mod_GeoIP_Header'                  => 'GeoIP фильтрация трафика',
    'mod_GeoIP_EnableFilter'            => 'Включить GeoIP фильтрацию',
    'mod_GeoIP_LastUpdate'              => 'Последнее обновление',
    'mod_GeoIP_BlockedSubnets'          => 'Заблокировано подсетей',
    'mod_GeoIP_UpdateNow'               => 'Обновить сейчас',
    'mod_GeoIP_IpsetUnavailableTitle'   => 'ipset недоступен',
    'mod_GeoIP_IpsetUnavailable'        => 'Утилита ipset не найдена в системе. GeoIP фильтрация не будет применяться к трафику.',
    'mod_GeoIP_BlockAll'                => 'Заблокировать все',
    'mod_GeoIP_UnblockAll'              => 'Разблокировать все',
    'mod_GeoIP_SearchPlaceholder'       => 'Поиск по странам...',
    'mod_GeoIP_CountryName'             => 'Страна',
    'mod_GeoIP_Status'                  => 'Статус',
    'mod_GeoIP_Loading'                 => 'Загрузка списка стран...',
    'mod_GeoIP_LoadError'               => 'Ошибка загрузки списка стран',
    'mod_GeoIP_Blocked'                 => 'Заблокирована',
    'mod_GeoIP_Allowed'                 => 'Разрешена',
    'mod_GeoIP_OtherCountries'          => '--- Остальные страны ---',
    'mod_GeoIP_NeverUpdated'            => 'Данные ещё не загружены',
    'mod_GeoIP_UpdateSuccess'           => 'База GeoIP успешно обновлена',
    'mod_GeoIP_UpdateError'             => 'Не удалось запустить обновление базы',
    'mod_GeoIP_Preparing'               => 'подготовка...',
    'mod_GeoIP_FilterAll'               => 'Все страны',
    'mod_GeoIP_FilterAllowed'           => 'Только разрешённые',
    'mod_GeoIP_FilterBlocked'           => 'Только заблокированные',
    'mod_GeoIP_DataSource'              => 'Источник данных',
    'mod_GeoIP_DataSourceDBIP'          => 'DB-IP Lite (рекомендуется)',
    'mod_GeoIP_DataSourceRIR'           => 'RIR delegation files',
    'mod_GeoIP_DataSourceIpdeny'        => 'ipdeny.com (агрегированные)',
];
