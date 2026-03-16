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

/**
 * Static reference of ISO 3166-1 alpha-2 countries.
 *
 * Country data is loaded from a separate data file to keep this class clean.
 * Flag mappings use Fomantic UI flag CSS classes.
 */
class GeoIPCountryList
{
    /** @var array|null Cached country data (English) */
    private static $countries = null;

    /** @var array|null Cached country data (Russian) */
    private static $countriesRu = null;

    /** @var array|null Cached flag mappings */
    private static $flags = null;

    /**
     * Prioritized country codes (from MikoPBX LanguageProvider languages).
     * These appear first in the UI country list.
     */
    private const PRIORITIZED = [
        'GB', 'RU', 'DE', 'DK', 'ES', 'GR', 'FR', 'IT', 'JP',
        'NL', 'PL', 'PT', 'RO', 'SE', 'CZ', 'TR', 'UA', 'VN',
        'CN', 'KR', 'TH', 'GE', 'AZ', 'KZ', 'US', 'BR', 'FI',
        'NO', 'HU',
    ];

    /**
     * Get all countries [code => english_name].
     *
     * @return array
     */
    public static function getAll(): array
    {
        if (self::$countries === null) {
            self::loadData();
        }
        return self::$countries;
    }

    /**
     * Get Fomantic UI flag class mappings [code => flag_class].
     *
     * @return array
     */
    public static function getFlags(): array
    {
        if (self::$flags === null) {
            self::loadData();
        }
        return self::$flags;
    }

    /**
     * Get prioritized country codes (shown first in UI).
     *
     * @return array
     */
    public static function getPrioritizedCodes(): array
    {
        return self::PRIORITIZED;
    }

    /**
     * Get localized country name.
     * Falls back to English name if localization is not available.
     *
     * @param string $cc Country code
     * @param string $lang Language code
     * @return string
     */
    public static function getLocalizedName(string $cc, string $lang = 'en'): string
    {
        if (self::$countries === null) {
            self::loadData();
        }
        if ($lang === 'ru' && !empty(self::$countriesRu[$cc])) {
            return self::$countriesRu[$cc];
        }
        return self::$countries[$cc] ?? $cc;
    }

    /**
     * Load country data from the data file.
     */
    private static function loadData(): void
    {
        $dataFile = __DIR__ . '/GeoIPCountryData.php';
        if (file_exists($dataFile)) {
            $data = require $dataFile;
            self::$countries = $data['countries'] ?? [];
            self::$countriesRu = $data['countries_ru'] ?? [];
            self::$flags = $data['flags'] ?? [];
        } else {
            self::$countries = [];
            self::$flags = [];
        }
    }
}
