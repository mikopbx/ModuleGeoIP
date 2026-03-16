# ModuleGeoIP — GeoIP Filtering for MikoPBX

## Цель

Модуль гео-фильтрации трафика по странам на основе ipset. Блокирует входящие соединения из выбранных стран на уровне iptables, работая совместно с существующим файрволом MikoPBX.

## Предпосылки

- Ядро Linux: `CONFIG_IP_SET` + 16 типов hash/bitmap + `CONFIG_NETFILTER_XT_SET`
- Бинарник `/usr/sbin/ipset` (v7.24) + libmnl
- Core хук `onAfterIptablesReload()` (коммит `651571d53` в Core develop)

## Архитектура: приоритет правил iptables

```
INPUT chain:
1. ESTABLISHED,RELATED → ACCEPT          (Core)
2. SIP rate limiting                      (Core)
3. Firewall rules (явные подсети)  → ACCEPT  ← ВЫШЕ ПРИОРИТЕТ
4. SIP providers + localhost       → ACCEPT  ← ВЫШЕ ПРИОРИТЕТ
5. Custom firewall rules                  (Core)
6. onAfterIptablesReload hook      → DROP   ← МОДУЛЬ GEOIP
7. Final DROP                             (Core)
```

Явные подсети из "Сетевой экран" и IP провайдеров ACCEPTятся ДО GeoIP — приоритет гарантирован архитектурно.

---

## Файловая структура модуля

```
ModuleGeoIP/
├── module.json                                # Метаданные модуля
├── composer.json                              # PHP зависимости (GuzzleHttp)
├── LICENSE
├── README.md                                  # Этот файл
│
├── Setup/
│   └── PbxExtensionSetup.php                  # Установка/удаление модуля
│
├── Lib/
│   ├── GeoIPConf.php                          # ConfigClass — хуки системы
│   │                                          #   onAfterIptablesReload()
│   │                                          #   getModuleWorkers()
│   │                                          #   moduleRestAPICallback()
│   │                                          #   onBeforeHeaderMenuShow()
│   │
│   ├── GeoIPSetManager.php                    # Управление ipset
│   │                                          #   isAvailable(): bool
│   │                                          #   setsExist(): bool
│   │                                          #   rebuildSets(array $countryCodes): void
│   │                                          #   destroySets(): void
│   │                                          #   getStats(): array
│   │
│   ├── GeoIPCountryLookup.php                 # Определение страны по IP
│   │                                          #   lookupCountry(string $ip): ?string
│   │                                          #   (reverse lookup по скачанным CIDR)
│   │                                          #   (кэш Redis: IP → cc, TTL 1 час)
│   │
│   ├── GeoIPCountryList.php                   # Статический список 249 стран ISO 3166-1
│   │                                          #   getAll(): array [cc => name_en]
│   │                                          #   getLocalizedName(cc, lang): string
│   │                                          #   getPrioritized(): array (29 из LanguageProvider первыми)
│   │
│   ├── WorkerGeoIPUpdater.php                 # PID-based worker
│   │                                          #   Скачивает ВСЕ 249 CIDR-файлов
│   │                                          #   ipdeny.com (IPv4 + IPv6)
│   │                                          #   Cache TTL: 604800 (7 дней) + jitter
│   │                                          #   Пересобирает ipset, reload firewall
│   │
│   └── RestAPI/
│       ├── GeoIPManagementProcessor.php       # Диспетчер REST API
│       └── GeoIP/
│           ├── GetListAction.php              # GET /geoip — все страны + blocked + adminCountry
│           ├── SaveAction.php                 # POST /geoip — сохранить blocked countries
│           ├── EnableAction.php               # POST /geoip:enable
│           ├── DisableAction.php              # POST /geoip:disable
│           ├── GetStatusAction.php            # GET /geoip:status
│           └── UpdateNowAction.php            # POST /geoip:updateNow
│
├── Models/
│   ├── ModuleGeoIP.php                        # Настройки модуля
│   │                                          #   enabled: string(1) '0'/'1'
│   │                                          #   lastUpdate: string (timestamp)
│   │
│   └── GeoFilterCountries.php                 # Заблокированные страны
│                                              #   country_code: string(2) UNIQUE
│                                              #   blocked: string(1) default '0'
│
├── App/
│   ├── Controllers/
│   │   └── ModuleGeoIPController.php          # Web UI контроллер
│   │                                          #   indexAction() — главная страница
│   │
│   ├── Forms/
│   │   └── ModuleGeoIPForm.php                # Форма настроек (enable toggle)
│   │
│   └── Views/
│       └── ModuleGeoIP/
│           └── index.volt                     # Volt шаблон
│                                              #   Toggle "Включить GeoIP"
│                                              #   Кнопки массовых операций
│                                              #   Таблица стран
│                                              #   Статус панель
│
├── public/
│   └── assets/
│       ├── css/
│       │   └── module-geoip.css               # Стили (таблица стран, toggles)
│       ├── js/
│       │   ├── module-geoip-index.js          # Compiled ES5
│       │   └── src/
│       │       └── module-geoip-index.js      # ES6 source
│       │                                      #   Searchable таблица стран
│       │                                      #   Toggle per country (Fomantic UI)
│       │                                      #   "Заблокировать все кроме выбранных"
│       │                                      #   "Разблокировать все"
│       │                                      #   Warning самоблокировки
│       │                                      #   Статус: last update, blocked CIDRs
│       │                                      #   "Обновить сейчас"
│       └── img/
│           └── logo.svg                       # Иконка модуля
│
├── Messages/
│   ├── ru.php                                 # Русские переводы (первичный)
│   ├── en.php                                 # Английские переводы
│   └── [27 других языков].php                 # Остальные переводы
│
└── db/
    └── folder4db                              # Placeholder для SQLite
```

---

## Детальное описание компонентов

### 1. GeoIPConf (Lib/GeoIPConf.php)

Extends `ConfigClass`. Реализует хуки:

```php
class GeoIPConf extends ConfigClass
{
    // Инжект iptables-правил после ACCEPT, перед DROP
    public function onAfterIptablesReload(): void
    {
        $settings = ModuleGeoIP::findFirst();
        if (!$settings || $settings->enabled !== '1') {
            return;
        }
        if (!GeoIPSetManager::isAvailable() || !GeoIPSetManager::setsExist()) {
            return;
        }
        // iptables -A INPUT -m set --match-set geoip_blocked_v4 src -j DROP
        // ip6tables -A INPUT -m set --match-set geoip_blocked_v6 src -j DROP
    }

    // Регистрация воркера
    public function getModuleWorkers(): array
    {
        return [
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_PID_NOT_ALERT,
                'worker' => WorkerGeoIPUpdater::class,
            ],
        ];
    }

    // REST API callback
    public function moduleRestAPICallback(array $request): PBXApiResult
    {
        return (new GeoIPManagementProcessor())->callBack($request);
    }

    // Пункт меню в сайдбаре
    public function onBeforeHeaderMenuShow(array &$menuItems): void
    {
        $menuItems[] = [
            'caption'   => 'GeoIP Filter',
            'iconClass' => 'globe',
            'group'     => 'network',
            'href'      => '/module-geo-ip/',
        ];
    }

    // Очистка при отключении модуля
    public function onAfterModuleDisable(): void
    {
        GeoIPSetManager::destroySets();
    }
}
```

### 2. GeoIPSetManager (Lib/GeoIPSetManager.php)

Управление ipset sets:

- **Два set**: `geoip_blocked_v4` (hash:net, maxelem 500000) и `geoip_blocked_v6` (hash:net, family inet6)
- **Атомарное обновление**:
  1. `ipset create geoip_blocked_v4_tmp hash:net maxelem 500000`
  2. Формирование restore-файла из CIDR-файлов заблокированных стран
  3. `echo "$restoreData" | ipset restore` (bulk, тысячи CIDR за секунды)
  4. `ipset swap geoip_blocked_v4_tmp geoip_blocked_v4`
  5. `ipset destroy geoip_blocked_v4_tmp`
  6. Аналогично для IPv6
- **Первый запуск**: если основной set не существует, создаёт его перед swap

### 3. WorkerGeoIPUpdater (Lib/WorkerGeoIPUpdater.php)

PID-based worker (паттерн `WorkerMarketplaceChecker`):

1. Проверяет managed cache `GeoIP:lastCheck`
2. Если expired:
   - Проверяет enabled в настройках модуля
   - Скачивает ВСЕ 249 CIDR-файлов через GuzzleHttp (30s timeout per file)
     - IPv4: `https://www.ipdeny.com/ipblocks/data/aggregated/{cc}-aggregated.zone`
     - IPv6: `https://www.ipdeny.com/ipv6/ipaddresses/aggregated/{cc}-aggregated.zone`
   - Сохраняет в `/storage/usbdisk1/mikopbx/tmp/geoip/{cc}.zone` и `{cc}-v6.zone`
   - Получает список blocked стран из `GeoFilterCountries`
   - Вызывает `GeoIPSetManager::rebuildSets($blockedCodes)`
   - Вызывает `IptablesConf::reloadFirewall()`
   - Обновляет `lastUpdate` в настройках модуля
   - Cache TTL: 604800 (7 дней) + random(0, 3600)
3. Сбой скачивания отдельной страны → пропуск, лог, остальные работают

### 4. GeoIPCountryLookup (Lib/GeoIPCountryLookup.php)

Определение страны IP-адреса по скачанным CIDR:

- Использует `IpAddressHelper::isIpInCidr()` из Core
- Приоритет проверки: 29 стран из `LanguageProvider::AVAILABLE_LANGUAGES` (быстрый hit)
- Кэш в Redis: `GeoIP:lookup:{ip}` → country_code, TTL 3600
- Возвращает `null` если файлы не скачаны или IP не найден

### 5. GeoIPCountryList (Lib/GeoIPCountryList.php)

Статический справочник 249 стран ISO 3166-1 alpha-2:

- `getAll(): array` — все страны `['RU' => 'Russia', 'CN' => 'China', ...]`
- `getPrioritized(string $lang): array` — 29 из LanguageProvider первыми, остальные по алфавиту на языке `$lang`
- `getLocalizedName(string $cc, string $lang): string` — имя страны на языке интерфейса
- Флаги: mapping country_code → Fomantic UI flag class (`'RU' => 'russia'`, `'US' => 'united states'`)

### 6. REST API

**Endpoints (через moduleRestAPICallback):**

| Метод | Action | Описание |
|-------|--------|----------|
| GET | getList | Все 249 стран + blocked status + adminCountry (через GeoIPCountryLookup) |
| POST | save | Массив blocked country codes → пересохранить GeoFilterCountries |
| POST | enable | Включить модуль, запустить первое скачивание если нет файлов |
| POST | disable | Выключить, вызвать destroySets() |
| GET | getStatus | enabled, lastUpdate, кол-во blocked CIDR, ipset stats |
| POST | updateNow | Сбросить cache worker → немедленное обновление |

**getList response:**
```json
{
  "result": true,
  "data": {
    "enabled": true,
    "adminCountry": "RU",
    "countries": [
      {"code": "GB", "name": "United Kingdom", "flag": "united kingdom", "blocked": false, "priority": true},
      {"code": "RU", "name": "Россия", "flag": "russia", "blocked": false, "priority": true},
      {"code": "CN", "name": "Китай", "flag": "china", "blocked": true, "priority": true},
      ...
      {"code": "AF", "name": "Афганистан", "flag": "afghanistan", "blocked": true, "priority": false},
      ...
    ]
  }
}
```

### 7. UI (index.volt + module-geoip-index.js)

```
┌─────────────────────────────────────────┐
│ ☑ Включить GeoIP фильтрацию            │
│                                         │
│ [Заблокировать все кроме выбранных]     │
│ [Разблокировать все]                    │
│                                         │
│ 🔍 Поиск по странам...                  │
│ ┌─────────────────────────────────────┐ │
│ │ 🇬🇧 United Kingdom     [Разрешено] │ │  ← 29 приоритетных
│ │ 🇷🇺 Россия             [Разрешено] │ │    (из LanguageProvider)
│ │ 🇩🇪 Германия           [Разрешено] │ │
│ │ 🇨🇳 Китай              [Заблокир.] │ │
│ │ ...                               │ │
│ │ ── остальные ~220 по алфавиту ──  │ │
│ └─────────────────────────────────────┘ │
│                                         │
│ ⚠ Вы блокируете страну, из которой     │
│   сейчас подключены!                    │
│                                         │
│ Последнее обновление: 12.03.2026        │
│ Заблокировано подсетей: 45,231          │
│ [Обновить сейчас]                       │
└─────────────────────────────────────────┘
```

**Поведение кнопок:**

- **"Заблокировать все кроме выбранных"** — массово ставит blocked=1 всем, кроме тех кто в "Разрешено". Страна админа (из `adminCountry`) автоматически остаётся в "Разрешено"
- **"Разблокировать все"** — сброс всех стран в "Разрешено"
- **Предупреждение** — красный banner если blocked включает adminCountry
- **"Обновить сейчас"** — POST updateNow, показать spinner до завершения

### 8. Модели

**ModuleGeoIP** (настройки модуля):
```
m_ModuleGeoIP
├── id          integer PK
├── enabled     string(1) default '0'
└── lastUpdate  string (ISO timestamp)
```

**GeoFilterCountries** (заблокированные страны):
```
m_GeoFilterCountries
├── id            integer PK
├── country_code  string(2) UNIQUE  — ISO 3166-1 alpha-2
└── blocked       string(1) default '0'
```

---

## Зависимости Core

| Компонент | Класс/Метод | Назначение |
|-----------|-------------|------------|
| Хук iptables | `SystemConfigInterface::ON_AFTER_IPTABLES_RELOAD` | Инжект правил ipset |
| CIDR проверка | `IpAddressHelper::isIpInCidr()` | Reverse lookup страны |
| Список языков | `LanguageProvider::AVAILABLE_LANGUAGES` | Приоритет стран в UI |
| Файрвол | `System::canManageFirewall()` | Проверка в Core перед хуком |
| Файрвол reload | `IptablesConf::reloadFirewall()` | Перезагрузка правил |

## Docker/LXC

- `System::canManageFirewall()` проверяется в Core перед вызовом хука
- Worker скачивает данные в любом окружении
- ipset/iptables команды — только когда canManageFirewall() === true (проверка в GeoIPConf::onAfterIptablesReload)
- UI показывает информационный notice если canManageFirewall() === false

## Обработка ошибок

| Ситуация | Поведение |
|----------|-----------|
| Нет `/usr/sbin/ipset` | Модуль не применяет правила, UI предупреждение |
| Сбой скачивания страны | Пропуск, лог, остальные применяются |
| Пустой CIDR файл | Пропуск страны |
| ipdeny.com недоступен | Лог, существующие sets в ядре сохраняются |
| Первое включение, нет данных | Worker начинает скачивание, UI показывает "Загрузка данных..." |

## Источник данных

- **ipdeny.com** — CIDR-файлы по странам (free, без API ключей)
  - IPv4: `https://www.ipdeny.com/ipblocks/data/aggregated/{cc}-aggregated.zone`
  - IPv6: `https://www.ipdeny.com/ipv6/ipaddresses/aggregated/{cc}-aggregated.zone`
- Обновление: раз в неделю + random jitter
- ~15-20MB суммарно за все 249 стран
- Формат: один CIDR на строку (например `1.0.0.0/24`)
- CIDR данные стабильны (99%+ не меняется месяцами)
