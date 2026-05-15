# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

ModuleGeoIP — модуль гео-фильтрации трафика для MikoPBX. Блокирует входящие соединения из выбранных стран на уровне iptables через ipset. README.md содержит полную техническую спецификацию.

## Project Status

Модуль реализован и задеплоен на тестовый сервер `boffart.miko.ru`.

## MikoPBX Module Architecture

Модуль следует стандартной структуре расширений MikoPBX (Phalcon MVC framework):

- **Namespace**: `Modules\ModuleGeoIP\` (PSR-4)
- **Entry point**: `Lib/GeoIPConf.php` extends `ConfigClass` — регистрирует хуки, воркеры, REST API, пункт меню
- **Setup**: `Setup/PbxExtensionSetup.php` extends `PbxExtensionSetupBase` — установка/удаление, создание таблиц БД
- **Models**: Phalcon ORM модели в `Models/`, таблицы с префиксом `m_`
- **REST API**: Dispatcher pattern — `GeoIPManagementProcessor` → Action классы в `Lib/RestAPI/GeoIP/`
- **Worker**: PID-based фоновый процесс `WorkerGeoIPUpdater` (паттерн `CHECK_BY_PID_NOT_ALERT`)
- **Web UI**: Controller + Volt template + Fomantic UI, JS в `public/assets/js/src/` (ES6) → компилируется в ES5
- **i18n**: Файлы переводов в `Messages/` (29 языков)

## Build & Development Commands

```bash
# Установка PHP-зависимостей
composer install

# Компиляция JS (ES6 → ES5) — через MikoPBXUtils (Babel)
# Путь: /Volumes/DevDisk/apor/Developement/MikoPBX/MikoPBXUtils/
# Исходник: public/assets/js/src/module-geoip-index.js
# Результат: public/assets/js/module-geoip-index.js

# Обновление офлайн-базы DB-IP Lite перед коммитом/релизом
./scripts/update-offline-db.sh

# Проверка PHP-синтаксиса
php -l <file.php>
```

Формальные тесты (phpunit) в MikoPBX модулях не используются — валидация происходит на уровне системы PBX.

## Offline GeoIP Database

`db/dbip-country-lite.csv.gz` — зашитая в репозиторий копия DB-IP Lite (CC BY 4.0). Клиенты часто работают в ограниченных сетях и не могут стабильно скачать базу с `download.db-ip.com`, поэтому `DBIPDataProvider` сначала пробует офлайн-файл и только при его отсутствии идёт онлайн.

- Источник: `https://download.db-ip.com/free/dbip-country-lite-YYYY-MM.csv.gz` (~4 МБ, обновляется первого числа каждого месяца)
- Обновление **только вручную** перед релизом:
  ```bash
  ./scripts/update-offline-db.sh                # текущий месяц с fallback на предыдущий
  ./scripts/update-offline-db.sh 2026-04        # конкретный месяц
  git status db/                                # убедиться что есть diff
  git add db/dbip-country-lite.csv.gz && git commit -m "chore: refresh offline DB-IP Lite"
  ```
- При зашитой базе старше 90 дней `DBIPDataProvider` пишет предупреждение в syslog (`STALE_WARN_DAYS`), но продолжает работу — это сигнал релиз-менеджеру обновить пакет

## Key Dependencies

- **MikoPBX Core**: ConfigClass, PbxExtensionSetupBase, BaseController, ModulesModelsBase, IptablesConf, IpAddressHelper, LanguageProvider
- **Phalcon 5.8**: MVC framework, ORM, Volt templates
- **GuzzleHttp**: скачивание CIDR-файлов с ipdeny.com
- **Linux**: ipset v7.24, iptables/ip6tables, CONFIG_IP_SET в ядре

## Core Integration Points

- `onAfterIptablesReload()` — инжект DROP-правил ipset после всех ACCEPT-правил Core (приоритет: Firewall rules > SIP providers > GeoIP)
- `getModuleWorkers()` — регистрация WorkerGeoIPUpdater
- `moduleRestAPICallback()` — диспетчеризация REST-запросов
- `onBeforeHeaderMenuShow()` — пункт меню "GeoIP Filter" в сайдбаре
- `onAfterModuleDisable()` — очистка ipset sets

## Database Schema

- `m_ModuleGeoIP`: enabled (string '0'/'1'), lastUpdate (ISO timestamp)
- `m_GeoFilterCountries`: country_code (string(2) UNIQUE, ISO 3166-1 alpha-2), blocked (string '0'/'1'). Страна разрешена если запись отсутствует или `blocked='0'`.

Поля boolean хранятся как `string(1)` — стандарт MikoPBX.

## Deployment (Test Server)

Server: `serber@boffart.miko.ru`
Module path: `/storage/usbdisk1/mikopbx/custom_modules/ModuleGeoIP/`

```bash
# Деплой изменённых файлов
scp <file> serber@boffart.miko.ru:/storage/usbdisk1/mikopbx/custom_modules/ModuleGeoIP/<file>

# Перезапуск воркера (SafeScripts перезапустит через cron */1)
ssh serber@boffart.miko.ru "kill $(ps aux | grep SafeScriptsCore | grep -v grep | awk '{print $2}')"

# Сброс кэшей переводов/шаблонов
ssh serber@boffart.miko.ru "redis-cli -n 4 FLUSHDB && rm -rf /var/tmp/www_cache/volt/*"
```

- `bin/Globals.php` — симлинк на `/usr/www/src/Core/Config/Globals.php` (создаётся при установке модуля, при ручном деплое нужно создать)
- CSS/JS ассеты — через симлинки в `/usr/www/sites/admin-cabinet/assets/{css,js}/cache/ModuleGeoIP/`
- Симлинки ассетов создаются `PbxExtensionUtils::createAssetsLinks()` при установке/включении и могут пропадать после прошивки/ручного scp-деплоя. Симптом: 404 на `module-geoip-index.js`/`module-geoip.css`, в консоли «Refused to apply style … MIME type 'text/html'», UI пустой / список стран не отображается. Восстановление:
  ```bash
  ssh serber@boffart.miko.ru "MODDIR=/storage/usbdisk1/mikopbx/custom_modules/ModuleGeoIP/public/assets; CACHE=/usr/www/sites/admin-cabinet/assets; ln -sf \$MODDIR/img \$CACHE/img/cache/ModuleGeoIP && ln -sf \$MODDIR/css \$CACHE/css/cache/ModuleGeoIP && ln -sf \$MODDIR/js \$CACHE/js/cache/ModuleGeoIP"
  ```
- Nginx кэш браузера: `expires 3d` — для обновления стилей нужен hard refresh (Ctrl+Shift+R)

## REST API Auth

В `getPBXCoreRESTAdditionalRoutes()` последний параметр массива — флаг авторизации:
- `true` — требует Bearer token (или localhost)
- `false` — публичный endpoint без авторизации
Все маршруты модуля используют `true`. UI работает через сессию браузера (запросы идут с localhost через php-fpm).

REST endpoints (`base = /pbxcore/api/modules/ModuleGeoIP`):
- `GET  $base/getList` — список стран + статус блокировки
- `POST $base/save` — сохранение настроек
- `GET  $base/getStatus` — состояние ipset/cron
- `POST $base/updateNow` — ручное обновление CIDR
Диспетчер actions: `Lib/RestAPI/GeoIPManagementProcessor.php`.

## Reference Modules

Для примеров реализации смотреть соседние модули в `/Volumes/DevDisk/apor/Developement/MikoPBX/Extensions/`:
- **ModuleAutoDialer** — комплексный модуль с воркерами, REST API, CLAUDE.md
- **ModulePT1CCore** — простой модуль с REST API
- **ModuleBackupManager** — воркеры, REST API, transport-паттерн
