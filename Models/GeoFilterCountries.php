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

namespace Modules\ModuleGeoIP\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

class GeoFilterCountries extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * ISO 3166-1 alpha-2 country code
     * @Column(type="string", length=2, nullable=false)
     */
    public $country_code;

    /**
     * Blocked flag ('0' or '1')
     * @Column(type="string", length=1, default="0", nullable=true)
     */
    public $blocked;

    public function initialize(): void
    {
        $this->setSource('m_GeoFilterCountries');
        parent::initialize();
    }
}
