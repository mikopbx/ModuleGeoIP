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

namespace Modules\ModuleGeoIP\App\Controllers;

use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleGeoIP\App\Forms\ModuleGeoIPForm;
use Modules\ModuleGeoIP\Models\ModuleGeoIP;

class ModuleGeoIPController extends BaseController
{
    private $moduleUniqueID = 'ModuleGeoIP';
    private $moduleDir;

    /**
     * Initialize controller.
     */
    public function initialize(): void
    {
        $this->moduleDir = PbxExtensionUtils::getModuleDir($this->moduleUniqueID);
        $this->view->logoImagePath = "{$this->url->get()}assets/img/cache/{$this->moduleUniqueID}/logo.svg";
        $this->view->submitMode = null;
        parent::initialize();
    }

    /**
     * Render the main module page.
     */
    public function indexAction(): void
    {
        $footerCollection = $this->assets->collection('footerJS');
        $footerCollection->addJs('js/vendor/datatable/dataTables.semanticui.js', true);
        $footerCollection->addJs('js/pbx/main/form.js', true);
        $footerCollection->addJs("js/cache/{$this->moduleUniqueID}/module-geoip-index.js", true);

        $headerCollectionCSS = $this->assets->collection('headerCSS');
        $headerCollectionCSS->addCss('css/vendor/datatable/dataTables.semanticui.min.css', true);
        $headerCollectionCSS->addCss("css/cache/{$this->moduleUniqueID}/module-geoip.css", true);

        $settings = ModuleGeoIP::findFirst();
        if ($settings === null) {
            $settings = new ModuleGeoIP();
        }

        $this->view->form = new ModuleGeoIPForm($settings);
        $this->view->pick("{$this->moduleDir}/App/Views/ModuleGeoIP/index");
    }

    /**
     * Save module settings.
     */
    public function saveAction(): void
    {
        $data = $this->request->getPost();
        $this->db->begin();

        $record = ModuleGeoIP::findFirst();
        if ($record === null) {
            $record = new ModuleGeoIP();
        }

        foreach ($record as $key => $value) {
            if ($key === 'id') {
                continue;
            }
            if (array_key_exists($key, $data)) {
                $record->$key = $data[$key];
            }
        }

        if ($record->save() === false) {
            $errors = $record->getMessages();
            $this->flash->error(implode('<br>', $errors));
            $this->view->success = false;
            $this->db->rollback();
            return;
        }

        $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
        $this->view->success = true;
        $this->db->commit();
    }
}
