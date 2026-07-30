# PigeonExpress_Shipping (Magento 2.4.7) — Architecture and Gap Analysis

Read-only analysis. No files were modified.

**Module root:** `/home/pigeonexpress/web/pigeonexpress.softart.work/public_html/magento_app/app/code/PigeonExpress/Shipping`
**SDK root:** `/home/pigeonexpress/web/pigeonexpress.softart.work/public_html/magento_app/packages/pigeonexpress-php-sdk`
**Spec:** `/tmp/claude-1009/.../scratchpad/pigeonexpress-openapi.yaml` (OpenAPI 3.0.3, 17 operations)

All paths below are relative to the module root unless stated otherwise. Absolute paths are given in section 7 and in the summary.

### Note on repository state at time of analysis

The module working copy is on git branch `feature/api-driven-service-fees` (HEAD `661eb18`, the single initial commit). At the time of reading there were **uncommitted, in-flight changes by a concurrent process**:

- `etc/db_schema.xml` modified: the `sales_order.pigeonexpress_cod_fee` comment was changed from `Pigeon Express COD Fee (2.5% of subtotal)` to `Pigeon Express COD Fee as returned by the API for this order`, and a new table `pigeonexpress_additional_service` was added (columns `code`, `name`, `description`, `input_type`, `option_group`, `is_active`, `last_fee_amount`, `last_fee_basis`, `fee_synced_at`, `synced_at`).
- New untracked files: `Model/AdditionalService.php` (152 lines, defines `CODE_COD_FEE = 'cod_fee'`) and `Model/ResourceModel/AdditionalService.php` (20 lines).

This analysis describes the **committed baseline** (`git show HEAD`), because that is the state the brief refers to. Section 7 flags which of the hardcoded-2.5% sites the in-flight work has already touched.

---

## 1. ARCHITECTURE MAP

Roughly 10,200 lines across 342 files (excluding `.git`). PHP 8.1/8.2/8.3, `type: magento2-module`, license OSL-3.0 (`composer.json:5`), requires `pigeonexpress/php-sdk: ^1.0` (`composer.json:13`). Module sequence: `Magento_Shipping`, `Magento_Quote`, `Magento_Store`, `Magento_Checkout`, `Magento_Cron` (`etc/module.xml:9-15`).

`registration.php:16-28` additionally registers an ad-hoc `spl_autoload_register` (prepended) for the `PigeonExpress\` prefix pointing at `packages/pigeonexpress-php-sdk/src`. This intercepts every class load whose FQCN starts with `PigeonExpress\`, including the module's own `PigeonExpress\Shipping\*` classes, and does a `file_exists()` syscall for each.

### 1.1 Carrier and rating

| Class | Role |
|---|---|
| `Model/Carrier/PigeonExpress.php` | `AbstractCarrier` + `CarrierInterface`. `_code = 'pigeonexpress'` (line 29). `collectRates()` (line 85) emits one rate method per enabled delivery type (`address`, `office`, `aps`), catching `\Throwable` per type and skipping that type on error (lines 129-140). `getAllowedMethods()` (line 189) returns `[carrierCode => 'address,office,aps']`. |
| `Model/Rate/RateCollector.php` | The real work. `getRatePrice()` (line 70) branches on pricing mode: `fixed` returns the flat rate from config (line 83), `dynamic` builds a payload and calls the API (lines 87-97). `buildCalculatePayload()` (line 134) assembles pickup, delivery, packages, and `service_codes`. `buildPackageDimensions()` (line 306), `cartHasDimensions()` (line 403), `calculateCartWeight()` (line 569), `buildPackages()` (line 535, 30 kg splitting), `buildServiceCodes()` (line 770), `getCodAmountForCalculation()` (line 817), `resolveCityApiId()` (line 725). |
| `Model/Rate/LocationToApiIdResolver.php` | Maps a local `entity_id` in `pigeonexpress_office` / `pigeonexpress_aps` to the PE API `api_id` (line 41), plus `getFirstActiveOfficeApiId()` (line 80) and `getFirstActiveApsApiId()` (line 92) used as placeholders before the customer picks a location. |
| `Model/Total/Quote/CodFee.php` | Quote total collector, registered at `etc/sales.xml:6-8` with `sort_order=450`. Computes a 2.5% / 0.80 minimum COD fee and **merges it into the shipping amount** rather than emitting a separate total (lines 55-64). `fetch()` returns `[]` (line 69). |
| `Plugin/Shipping/ShippingLogPlugin.php` | Diagnostic `before`/`around` plugin on `Magento\Shipping\Model\Shipping` (`etc/di.xml:106-109`), logging the active carrier list and wrapping `collectCarrierRates`. |

### 1.2 Checkout frontend

Injected structurally by `Plugin/Checkout/LayoutProcessorPlugin.php:27-67` (plugin declared at `etc/di.xml:102-105`), which writes three components into `shippingAddress.children` at `displayArea: shippingAdditional`.

| Asset | Role |
|---|---|
| `view/frontend/web/js/view/checkout/shipping/location-autocomplete.js` | KO component (296 lines). Debounced search against `pigeonexpress/checkout/locationAutocomplete` (line 149). On selection posts to `pigeonexpress/checkout/setLocation` (lines 230-247), rewrites the quote address street/city/postcode, clears the rate registry, and re-requests rates. Writes `window.pigeonexpressCheckoutPayload`. |
| `view/frontend/web/js/view/checkout/shipping/address-visibility.js` | Adds body classes, detaches logged-in customers from their saved address book entry (`detachFromCustomerAddress()` line 32), and stuffs placeholder values (`'PigeonExpress Location'`, postcode `'00000'`, lines 20-25) into hidden address fields so Magento validation passes for office/APS delivery. |
| `view/frontend/web/js/view/checkout/shipping/instructions.js` | Free-text delivery instructions; writes `window.pigeonexpressCheckoutPayload.instructions`. |
| `view/frontend/web/js/model/shipping-save-processor/payload-mixin.js` | Adds the five `pigeonexpress_*` keys to `addressInformation.extension_attributes`. |
| `view/frontend/web/js/model/shipping-save-processor/default-mixin.js` | Full replacement of `saveShippingInformation` adding client-side location/phone validation (lines 52-75). |
| `view/frontend/web/js/model/shipping-save-processor/bss-validate-mixin.js` | Same for the third-party `Bss_OneStepCheckout` processor. |
| `view/frontend/web/js/model/shipping-rate-registry-mixin.js` | Adds a `clear(key)` method to the rate registry so a location change forces a fresh estimate. |
| `view/frontend/web/js/view/shipping-mixin.js` | Forces the new-address form when a PE method is chosen. |
| `view/frontend/web/js/view/shipping-information-mixin.js` | Overrides the shipping-information template to show the chosen office/APS and comment. |
| `view/frontend/requirejs-config.js:5-28` | Registers 6 mixin bindings (rate registry, default save processor, two Bss ones, shipping-information, shipping). |
| `view/frontend/web/css/checkout/location-autocomplete.css`, `view/frontend/layout/checkout_index_index.xml:9` | Styling only. |

**Not registered anywhere (dead code):** `view/frontend/web/js/view/checkout/summary/cod-fee.js`, `view/frontend/web/template/checkout/summary/cod-fee.html`, `view/frontend/web/js/view/checkout/summary/grand-total-mixin.js`, `view/frontend/web/js/model/cod-fee.js`, `view/frontend/web/js/payment-cod-recalc.js`, `view/frontend/web/js/action/select-payment-method-mixin.js`. A repo-wide grep for each of these module paths returns only their own definitions. They appear neither in `requirejs-config.js` nor in any layout XML nor in `LayoutProcessorPlugin`.

### 1.3 Admin configuration

- `etc/adminhtml/system.xml` — 47 fields under `carriers/pigeonexpress` (full table in section 4).
- `Block/Adminhtml/System/Config/SyncButton.php` — renders a link styled as a button to `pigeonexpress/sync/schedule` (line 25).
- `Block/Adminhtml/System/Config/SearchableSelect.php` — wraps the pickup-office `<select>` with a filter input and an **inline `<script>` block** (lines 37-62).
- `Block/Adminhtml/System/Config/Separator.php` — `<hr>` decoration.
- Source models: `Model/Config/Source/{PaymentMethods,PickupOffice,PickupType,PricingMode,ProductAttribute,ReviewAndTest}.php`.
- `Model/Config/ConfigProvider.php` (483 lines) implements `Api/ConfigInterface.php`; bound at `etc/di.xml:74-75`. All reads go through `ScopeConfigInterface` at `ScopeInterface::SCOPE_STORE` (correct); API key/secret are decrypted through `EncryptorInterface` (lines 99-110).
- `etc/acl.xml` — `PigeonExpress_Shipping::send_shipment` (line 13), `PigeonExpress_Shipping::config_pigeonexpress` (line 22), `PigeonExpress_Shipping::sync` (line 26).
- `etc/adminhtml/routes.xml` — route `pigeonexpress` in the `admin` router.

### 1.4 API clients (`Model/Api/`)

| Class | Transport | Endpoint |
|---|---|---|
| `Model/Api/RateClient.php` | PHP SDK, `new PigeonExpress(...)` constructed inline per attempt (line 72) | `POST /v1/shipments/calculate` (line 81) |
| `Model/Api/ShipmentClient.php` | PHP SDK, inline construction (line 73) | `POST /v1/shipments` (line 93) |
| `Model/Api/LocationsClient.php` | Raw `Magento\Framework\HTTP\Client\Curl`, explicitly bypassing the SDK (header comment lines 2-4) | `GET /v1/offices?type=…` (line 159), `GET /v1/cities` (line 102) |
| `Model/Api/AddressResolver.php` | Raw Curl (line 206) | `GET /v1/cities/{id}/streets` (lines 169-170); city resolution comes from the local `pigeonexpress_city` table (lines 117-158) |

Retry/timeout constants: `RateClient.php:28,31` (3 attempts, 1200 ms, 30 s timeout at line 67); `ShipmentClient.php:29,32` (3 attempts, validation errors break out at line 125); `LocationsClient.php:31,34,37,40` (3 attempts, 1.5 s retry, 1 s page delay, 65 s rate-limit back-off, 30 s Curl timeout at lines 251/288); `AddressResolver.php:201` (20 s Curl timeout).

Interfaces in `Api/`: `ConfigInterface`, `LocationsClientInterface`, `LocationSearchInterface`, `LocationSyncInterface`, `RateClientInterface`, `ShipmentClientInterface`, `ShipmentSenderInterface`. All bound via `<preference>` at `etc/di.xml:74-87`. Note these are **internal** interfaces, not Magento service contracts: none are exposed via `etc/webapi.xml` (which does not exist), and none use `Api/Data/*Interface` DTOs. `RateClientInterface::calculate()` and friends pass and return raw `array`.

### 1.5 Persistence

- `etc/db_schema.xml` — 6 tables plus 1 column on `sales_order` (section 2).
- `etc/db_schema_whitelist.json` — matching whitelist for all 7.
- Flat models + resource models + collections: `Model/{Office,Aps,City,Shipment}.php`, `Model/ResourceModel/{Office,Aps,City,Shipment}.php` and their `Collection.php` subdirectories. All are bare `AbstractModel`/`AbstractDb`/`AbstractCollection` pairs with typed getters/setters. **There are no repositories and no `Api/Data` interfaces.**
- `Model/QuoteAddressLocationPersistor.php` and `Model/OrderAddressLocationPersistor.php` — thin `ResourceConnection` wrappers doing `insertOnDuplicate` (lines 54 / 62) and `fetchRow` (lines 68 / 76). They bypass the model layer entirely.
- `Model/LocationSearch.php` — office/APS autocomplete query, LEFT JOINs `pigeonexpress_city` to resolve city name/postcode (lines 65-76), escapes LIKE wildcards (lines 84-89), caps the limit at 50 (line 224).
- `Setup/Patch/Schema/DropQuoteAddressForeignKey.php` — deliberately drops the declarative FK from `pigeonexpress_quote_address` to `quote_address` because the save plugin can run before the address row is committed (header comment lines 3-5).

### 1.6 Cron and sync

- `etc/cron_groups.xml` — group `pigeonexpress`, `use_separate_process=0` (line 12), `schedule_lifetime=15` (line 8).
- `etc/crontab.xml:6-11` — job `pigeonexpress_sync_locations` → `Model\Cron\SyncLocations::execute`, schedule `0 2 * * *`.
- `Model/Cron/SyncLocations.php:45-74` — guards on `isActive()` and non-empty API key, then calls `syncAll()`, catching `ApiException` and `\Throwable` and logging.
- `Model/LocationSync.php` — `syncOffices()` (73), `syncAps()` (82), `syncCities()` (91), `syncAll()` (127). `syncLocations()` (142) does a full read of the existing collection into memory, upserts through a `TransactionFactory` object transaction (line 186), and deactivates rows no longer returned by the API (lines 178-184).
- `Controller/Adminhtml/Sync/Schedule.php` and `Block/Adminhtml/System/Config/SyncButton.php` — manual trigger (section 3).

### 1.7 Order lifecycle (Observer/, Plugin/)

Location data travels: checkout JS → `ShippingInformationInterface` extension attributes → quote address extension attributes → `pigeonexpress_quote_address` → order address extension attributes → `pigeonexpress_order_address`.

| Hook | Declared at | Purpose |
|---|---|---|
| `Plugin/Checkout/ShippingInformationManagementValidationPlugin.php` | `etc/di.xml:89-90`, sortOrder 5 | Logging only; validation deliberately deferred (header comment lines 3-8). |
| `Plugin/Checkout/ShippingInformationManagementPlugin.php` | `etc/di.xml:91-92`, sortOrder 20 | Copies the 5 extension attributes from the request onto the quote address and persists them (lines 94-114). |
| `Plugin/Quote/QuoteAddressSavePlugin.php` | `etc/di.xml:115-118` | `afterSave` on `Quote\Address` → persistor (line 60). |
| `Plugin/Quote/QuoteAddressLoadPlugin.php` | `etc/di.xml:119-122` | `afterLoad` on the address resource model → repopulates extension attributes (lines 82-86). |
| `Plugin/Quote/QuoteShippingAddressPlugin.php` | `etc/di.xml:111-114` | `afterGetShippingAddress` fallback for addresses loaded via collection (lines 60-74). |
| `Plugin/Quote/QuoteManagementPlugin.php` | `etc/di.xml:98-101`, sortOrder 10 | `beforeSubmit`: forces `collectTotals()` (line 45), then requires a location for office/APS (line 86-89) and a valid phone (lines 92-98). |
| `Plugin/Quote/AddressToOrderAddressPlugin.php` | `etc/di.xml:94-97` | Copies extension attributes quote address → order address, and back-fills `pigeonexpress_delivery_price` from the shipping amount (lines 63-73). |
| `Plugin/Quote/ToOrderPlugin.php` | `etc/di.xml:139-142` | Copies the COD fee onto `sales_order.pigeonexpress_cod_fee` (lines 18-21). |
| `Plugin/Sales/OrderAddressSavePlugin.php` / `OrderAddressLoadPlugin.php` | `etc/di.xml:124-131` | Mirror of the quote-side pair, on `sales_order_address`. |
| `Observer/Sales/OrderPlaceAfter.php` | `etc/events.xml:3-5` | Adds the delivery instructions as an order status-history comment (line 44). |
| `Observer/Email/OrderTemplateVars.php` | `etc/events.xml:6-8` | Builds `pigeonexpress_html` for the email template (lines 86-104). |

### 1.8 Shipment creation

- `Model/ShipmentSender.php` (625 lines) — `send()` (line 83) guards on carrier prefix (line 93) and on an existing shipment row (lines 98-103), builds receiver, package, pickup, delivery, services, and `inventory_items`, calls `ShipmentClientInterface::create()` (line 199), and persists a `pigeonexpress_shipment` row including the full request and response JSON (lines 203-211). Static `normalizePhone()` (line 527) does Bulgarian phone normalisation.
- `Controller/Adminhtml/Order/Send.php` — `HttpPostActionInterface`, ACL `PigeonExpress_Shipping::send_shipment` (line 16), loads the order via `OrderRepositoryInterface` (line 58), accepts a `receiver_phone` override (lines 64-68).
- `Block/Delivery/Info.php` (435 lines) — dual-purpose block for storefront, success page, admin order view. Renders through `view/frontend/templates/delivery/info.phtml` and `view/adminhtml/templates/delivery/info.phtml`; the admin template also holds the "Send to Pigeon Express" form (lines 62-81) with a `form_key` hidden field (line 66).
- Layouts: `view/frontend/layout/checkout_onepage_success.xml`, `view/frontend/layout/sales_order_view.xml`, `view/adminhtml/layout/sales_order_view.xml`.

### 1.9 Emails

- `etc/email_templates.xml:4-5` registers `pigeonexpress_order_confirm` and `pigeonexpress_order_confirm_guest`, both backed by `view/frontend/email/order_new.html`.
- `Plugin/Sales/Order/Email/OrderSenderPlugin.php:33-44` switches the template at runtime by **reflecting into the private `templateContainer` property of `Magento\Sales\Model\Order\Email\Sender`**.
- `Observer/Email/OrderTemplateVars.php` supplies `pigeonexpress_show` and `pigeonexpress_html`; the template declares both in its `@vars` block.

---

## 2. DATA STORAGE

### 2.1 `etc/db_schema.xml` tables

**`pigeonexpress_office`** (lines 5-34) — "Pigeon Express Offices"

| Column | Type | Notes |
|---|---|---|
| `entity_id` | int unsigned, identity | PK |
| `api_id` | int unsigned, not null | PE API office id; UNIQUE `PIGEONEXPRESS_OFFICE_API_ID` |
| `name` | varchar(255) not null | indexed |
| `address` | text not null | |
| `city_id` | int unsigned nullable | PE API city id, joins to `pigeonexpress_city.api_id` |
| `city` | varchar(255) nullable | indexed. **Always written as NULL** by `Model/LocationSync.php:208`. |
| `country` | varchar(2) nullable, default `BG` | hardcoded `'BG'` at `LocationSync.php:209` |
| `postcode` | varchar(20) nullable | **Always written as NULL** by `LocationSync.php:210` |
| `latitude` / `longitude` | decimal(10,8) / decimal(11,8) | |
| `is_active` | smallint unsigned, default 1 | indexed; set to 0 when the row disappears from the API |
| `created_at` / `updated_at` | timestamp | |

**`pigeonexpress_aps`** (lines 36-65) — identical shape, "Pigeon Express APS (Lockers)". Same NULL-city/NULL-postcode behaviour.

**`pigeonexpress_city`** (lines 67-85) — `entity_id` PK, `api_id` (UNIQUE), `name` (BG, indexed, default `''`), `name_en`, `postal_code` (indexed). No `is_active`, no timestamps.

**`pigeonexpress_quote_address`** (lines 87-98) — one row per quote shipping address. PK `address_id` (= `quote_address.address_id`). Columns `pigeonexpress_delivery_type` varchar(32), `pigeonexpress_location_id` varchar(50), `pigeonexpress_location_name` varchar(255), `pigeonexpress_location_address` text, `pigeonexpress_instructions` text. **No foreign key** (comment at line 97 explains why; enforced by `Setup/Patch/Schema/DropQuoteAddressForeignKey.php`). Consequently nothing ever deletes these rows.

**`pigeonexpress_order_address`** (lines 100-114) — same five columns plus `pigeonexpress_delivery_price` decimal(12,4). PK `address_id` (= `sales_order_address.entity_id`), with FK `PIGEONEXPRESS_ORDER_ADDRESS_ADDRESS_ID_SALES_ORDER_ADDRESS_ENTITY_ID` and `onDelete="CASCADE"` (lines 111-113).

**`pigeonexpress_shipment`** (lines 121-144) — `entity_id` PK; `order_id` (FK to `sales_order.entity_id`, CASCADE, indexed); `reference_number` varchar(100) (indexed); `tracking_number` varchar(100); `status` varchar(32) default `sent`; `delivery_price` decimal(12,4); `payload` text (JSON of the request); `response` text (JSON of the API response); `sent_at`, `updated_at`. Note `payload`/`response` retain full customer PII (receiver name, phone, address) with no retention or redaction policy.

### 2.2 Columns added to core Magento tables

Exactly one, declaratively:

- **`sales_order.pigeonexpress_cod_fee`** — `decimal(12,4)` nullable, `etc/db_schema.xml:117-118`, whitelisted at `etc/db_schema_whitelist.json:101`. Written by `Plugin/Quote/ToOrderPlugin.php:20`. **Never read anywhere in the module.** No corresponding `quote`, `quote_address`, `sales_invoice` or `sales_creditmemo` column, and `etc/sales.xml` only registers the collector in the `quote` section (lines 4-10), not in `order`, `invoice` or `creditmemo`.

No columns are added to `quote_address` or `sales_order_address`. Instead the module uses **extension attributes backed by side tables**: `etc/extension_attributes.xml` declares `pigeonexpress_delivery_type`, `pigeonexpress_location_id`, `pigeonexpress_location_name`, `pigeonexpress_location_address`, `pigeonexpress_instructions`, `pigeonexpress_delivery_price` for `ShippingInformationInterface` (lines 9-16), `Quote\Api\Data\AddressInterface` (lines 18-25) and `Sales\Api\Data\OrderAddressInterface` (lines 27-34). Persistence for the latter two goes through `pigeonexpress_quote_address` / `pigeonexpress_order_address`.

`pigeonexpress_delivery_price` is declared on all three interfaces but is only ever persisted for order addresses (`OrderAddressLocationPersistor::save()` seventh argument); `QuoteAddressLocationPersistor::save()` has no such parameter, so the quote-side attribute is write-only in memory.

### 2.3 How offices, APS lockers and cities are stored and refreshed

1. `Model/Api/LocationsClient::fetchByType()` (line 135) pages `GET /v1/offices?type=office|locker&page=N&per_page=100` until a short page or a repeated id is seen (lines 171-197), sleeping 1 s between full pages (line 195), with 3 attempts per page and a 65 s back-off if the message contains "Too Many" (lines 219-241).
2. `LocationsClient::normalizeItem()` (line 327) keeps only `id`, `name`, `city_id` (from the nested `city.id`), `address`, `type`, `latitude`, `longitude`. Postcode and city name from the API response are discarded here.
3. `Model/LocationSync::syncLocations()` (line 142) loads the whole existing collection into a keyed array (lines 150-156), upserts every API row into `pigeonexpress_office` or `pigeonexpress_aps`, and flips `is_active=0` for locally known `api_id`s absent from the API (lines 178-184). Everything is queued into one object transaction and committed at line 186.
4. Cities go a different route: `LocationsClient::fetchCities()` (line 80) pages `GET /v1/cities` using `meta.last_page` (line 117), with **no retry and no inter-page delay** (it calls `doGetFull()` directly, not `getWithRetry()`). `LocationSync::syncCities()` (line 91) de-duplicates by `api_id` and does a single bulk `insertOnDuplicate` (line 112) updating `name`, `name_en`, `postal_code`. Cities are never deactivated and the returned `updated` count is always `0` (line 121).
5. Reads: `Model/LocationSearch.php` for checkout autocomplete (LEFT JOIN on `pigeonexpress_city` to recover the city name and postcode the sync threw away, lines 65-76); `Model/Config/Source/PickupOffice.php` for the admin dropdown; `Model/Rate/LocationToApiIdResolver.php` and `Model/Api/AddressResolver::findCityId()` (line 117) for payload building.

---

## 3. CRON AND SYNC

### 3.1 What is scheduled

`etc/crontab.xml:5-12` declares a single job in its own group:

```
group id="pigeonexpress"
  job pigeonexpress_sync_locations
    instance PigeonExpress\Shipping\Model\Cron\SyncLocations
    method   execute
    schedule 0 2 * * *          (daily at 02:00; a "* * * * *" variant is commented out at line 10)
```

`etc/cron_groups.xml:5-13`: `schedule_generate_every=15`, `schedule_ahead_for=20`, `schedule_lifetime=15`, `history_cleanup_every=10`, `history_success_lifetime=60`, `history_failure_lifetime=4320`, `use_separate_process=0`.

`schedule_lifetime=15` (minutes) combined with a job that can sleep for 65 s per rate-limit hit and 1 s per page is a real operational risk: a long sync blocks the group (no separate process) and a manually inserted schedule row that is not picked up within 15 minutes is marked `missed`.

### 3.2 What the job does

`Model/Cron/SyncLocations::execute()` (line 45):

1. Logs an unconditional start line (line 47) — this one bypasses the `enable_logging` flag.
2. Returns early if `ConfigInterface::isActive()` is false (lines 49-52) or if `getApiKey()` is empty (lines 53-56). Both checks use the **default scope** (no store id), so a per-website enablement is not honoured.
3. Calls `LocationSyncInterface::syncAll()` (line 60) → offices, then APS, then cities, in that order (`Model/LocationSync.php:127-134`).
4. Logs a stats line (lines 61-68), catching `ApiException` (line 69) and `\Throwable` (line 71).

### 3.3 Manual "Schedule Sync Now"

`Block/Adminhtml/System/Config/SyncButton.php` renders an anchor to `pigeonexpress/sync/schedule` (line 25), stripping the scope selector so the button reads as global (line 16). The field is declared at `etc/adminhtml/system.xml:270-276` (`type="button"`, `showInDefault="1"` only).

`Controller/Adminhtml/Sync/Schedule.php`:
- ACL `PigeonExpress_Shipping::sync` (line 48).
- Implements `HttpGetActionInterface` (line 16) — a state-changing GET.
- If a `pigeonexpress_sync_locations` row already exists with status `pending`, it adds a notice and returns (lines 59-68).
- Otherwise it inserts a `cron_schedule` row with `job_code`, `status=pending`, `created_at=scheduled_at=$this->dateTime->gmtDate()` (lines 70-77) and redirects back to the referer.

So the manual trigger is not a synchronous sync: it enqueues work that the next cron tick picks up (message text at line 80).

### 3.4 Staleness and last-sync recording

**There is none.** The module records:
- `pigeonexpress_office.updated_at` / `pigeonexpress_aps.updated_at` (declarative `on_update="true"`), which are per-row and only move if that row's data changed.
- Nothing at all on `pigeonexpress_city` (no timestamps).
- Log lines in `var/log/pigeonexpress.log` (`Model/LocationSync.php:117-119, 188-193`, `Model/Cron/SyncLocations.php:59-68`).
- Whatever `cron_schedule` retains, which `history_success_lifetime=60` prunes after an hour.

There is no `last_sync_at` config value, no status table, no admin display of when the catalogue was last refreshed, and no warning when it is stale. The only place a merchant sees anything is the log file.

---

## 4. ADMIN CONFIGURATION

All fields live under section `carriers`, group `pigeonexpress` (`etc/adminhtml/system.xml:9-10`), so the full config path prefix is `carriers/pigeonexpress/`. Defaults are in `etc/config.xml:8-62`.

| # | Field id | Path suffix | Type | Source / backend model | Scope | Purpose | Default |
|---|---|---|---|---|---|---|---|
| 1 | `active` (L14) | `active` | select | `Magento\Config\Model\Config\Source\Yesno` | D/W/S | Enable carrier | `0` |
| 2 | `title` (L19) | `title` | text | — | D/W/S | Carrier title | `Pigeon Express` |
| 3 | `name` (L23) | `name` | text | — | D/W/S | Carrier name | `Pigeon Express` |
| 4 | `api_key` (L26) | `api_key` | **obscure** | backend `…Config\Backend\Encrypted` | D/W | `X-API-Key` | empty |
| 5 | `api_secret` (L31) | `api_secret` | **obscure** | backend `…Config\Backend\Encrypted` | D/W | `X-API-Secret`; falls back to key | empty |
| 6 | `test_mode` (L36) | `test_mode` | select | Yesno | D/W/S | Use `api-demo.pigeonexpress.com` | `1` |
| 7 | `enable_logging` (L41) | `enable_logging` | select | Yesno | D/W/S | Gate most logging | `0` |
| 8 | `specificerrmsg` (L46) | `specificerrmsg` | textarea | — | D/W/S | Unavailable message | set |
| 9 | `sallowspecific` (L50) | `sallowspecific` | select | `Shipping\…\Allspecificcountries` | **hidden (0/0/0)** | Country restriction | `0` |
| 10 | `specificcountry` (L55) | `specificcountry` | multiselect | `Directory\…\Country` | **hidden** | Country list | — |
| 11 | `separator_additional_services` (L62) | — | note | frontend `…Config\Separator` | D/W/S | Visual `<hr>` | — |
| 12 | `cod_payment_methods` (L66) | `cod_payment_methods` | multiselect | `…Source\PaymentMethods` | D/W/S | Which payment methods trigger the COD fee | empty |
| 13 | `additional_services_note` (L72) | — | note | — | D/W/S | Static text | — |
| 14 | `service_return_at_my_expense` (L77) | `service_return_at_my_expense` | select | Yesno | D/W/S | → API `paper_return_receipt` | `0` |
| 15 | `service_requires_signature` (L83) | `service_requires_signature` | select | Yesno | D/W/S | → API `ID_verification_and_document_signature` | `0` |
| 16 | `service_special_packaging` (L89) | `service_special_packaging` | select | Yesno | D/W/S | → API `requires_special_packaging` | `0` |
| 17 | `service_declared_value` (L95) | `service_declared_value` | text | — | D/W/S | Fixed `declared_value` amount | `0` |
| 18 | `service_return_receipt` (L101) | `service_return_receipt` | select | Yesno | D/W/S | → API `return_receipt` | `0` |
| 19 | `service_return_documents` (L107) | `service_return_documents` | select | Yesno | D/W/S | → API `service_return_documents` | `0` |
| 20-24 | `address_enabled` (L115), `address_title` (L123), `address_note` (L130), `address_pricing_mode` (L136), `address_flat_rate` (L143) | same | select/text/textarea/select/text | Yesno, `…Source\PricingMode` | D/W/S | Address delivery | `1`, `Address Delivery`, ``, `fixed`, `0` |
| 25 | `address_review_and_test` (L151) | `address_review_and_test` | select | `…Source\ReviewAndTest` | D/W/S | → API `shipment_test_before_payment` | `no` |
| 26-30 | `office_enabled` (L161), `office_title` (L168), `office_note` (L174), `office_pricing_mode` (L180), `office_flat_rate` (L187) | same | as above | | D/W/S | Office delivery | `1`, `Office Delivery`, ``, `fixed`, `0` |
| 31 | `office_review_and_test` (L194) | `office_review_and_test` | select | `…Source\ReviewAndTest` | D/W/S | as #25 | `no` |
| 32-36 | `aps_enabled` (L204), `aps_title` (L211), `aps_note` (L217), `aps_pricing_mode` (L223), `aps_flat_rate` (L230) | same | as above | | D/W/S | APS delivery. **No `aps_review_and_test`** (correct: the spec says the service is not available for lockers) | `1`, `APS (Parcel Locker)`, ``, `fixed`, `0` |
| 37 | `separator_location` (L238) | — | note | `…Config\Separator` | D/W/S | Visual `<hr>` | — |
| 38 | `location_search_limit` (L242) | `location_search_limit` | text | — | D/W/S | Autocomplete result cap (hard-capped at 50 in `LocationSearch.php:224`) | `20` |
| 39 | `pickup_type` (L252) | `pickup_type` | select | `…Source\PickupType` | D/W/S | office / address | `office` |
| 40 | `pickup_office_id` (L260) | `pickup_office_id` | select | `…Source\PickupOffice`, frontend `…Config\SearchableSelect` | D/W/S | PE `api_id` of drop-off office | — |
| 41 | `sync_button` (L270) | — | button | frontend `…Config\SyncButton` | **D only** | Schedule a location sync | — |
| 42-46 | `pickup_address_city_id` (L279), `pickup_address_city_label` (L287), `pickup_address_street_id` (L294), `pickup_address_street_label` (L302), `pickup_address_street_number` (L309) | same | text | — | D/W/S | Pickup address parts | — |
| 47 | `pickup_address_postal_code` (L316) | same | text | — | D/W/S | Pickup postal code | — |
| 48 | `dimensions_note` (L325) | — | note | — | D/W/S | Static text | — |
| 49-52 | `weight_attribute` (L329), `length_attribute` (L337), `width_attribute` (L345), `height_attribute` (L353) | same | select | `…Source\ProductAttribute` | D/W/S | Attribute code mapping | — |
| 53-56 | `default_weight` (L361), `default_length` (L368), `default_width` (L375), `default_height` (L382) | same | text | — | D/W/S | Fallbacks; `0` means "hide the method" | `0` each |

### 4.1 Fields whose help text or behaviour implies a hardcoded business value

1. **`cod_payment_methods` comment, `etc/adminhtml/system.xml:69`** — *"Payment methods that trigger a COD fee (2.5% of subtotal, minimum 0.80). The fee is added to the shipping cost."* The percentage and floor are stated as fact in the UI but exist only in `Model/Total/Quote/CodFee.php:15-16`. There is no field to change them, and the API has no notion of them.
2. **`service_declared_value` comment, line 97** — *"Fixed declared value amount sent to PE API (Разширена застраховка)."* A single store-wide constant is sent as `declared_value` for every shipment regardless of cart value; the sensible business rule (declared value = order value) is not offered. The label is also partially untranslated Bulgarian in an `en_US` file.
3. **`service_special_packaging` comment, line 92** — *"Enable only after Pigeon Express activates this service."* An out-of-band contractual precondition encoded as prose; there is no capability check against `GET /v1/additional-services`, which exists precisely to answer this.
4. **`additional_services_note` comment, line 74** — *"You can see current prices on the Pigeon Express website or per your agreed terms."* An explicit admission that service prices, which the API can return, are not surfaced anywhere in the module.
5. **`location_search_limit` comment, line 245** — suggests "10–20" while the code silently clamps to 50 (`Model/LocationSearch.php:224`) and falls back to 20 (`ConfigProvider.php:215`).
6. **Missing field: `service_pos_payment`.** `Model/Rate/RateCollector.php:776` and `Model/ShipmentSender.php:565` both map config code `pos_payment` → API code `cod_card_fee`, and `ConfigProvider::isAdditionalServiceEnabled()` (line 447-454) reads `carriers/pigeonexpress/service_pos_payment`. No such field exists in `system.xml` and no default exists in `config.xml`, so `cod_card_fee` can never be switched on. Dead mapping.
7. **`*_review_and_test`**: `Model/Config/Source/ReviewAndTest.php:19-20` only offers `no` and `test`, but `RateCollector.php:792` and `ShipmentSender.php:580` also accept `'review'`. The spec's `shipment_review` service code (a separate `option_group: shipment_view` member) is never sent, so half the capability is unreachable.
8. **Hidden but live: `sallowspecific` / `specificcountry`** (lines 50-59, all three `showIn*` flags `0`). `AbstractCarrier::checkAvailableShipCountries()` still consults them, so a merchant can be blocked by a setting they cannot see or edit.

---

## 5. SDK COVERAGE GAP

Authentication for every operation is `X-API-Key` + `X-API-Secret`. Documented rate limits: 60 requests/min, 10/min for label retrieval.

### 5.1 Full endpoint matrix

| # | Method + path | operationId | SDK method | Used by module |
|---|---|---|---|---|
| 1 | `GET /v1/cities` | `listCities` | `Resources/Cities.php:19` `all()` | No (module re-implements it: `Model/Api/LocationsClient.php:102`) |
| 2 | `GET /v1/cities/{cityId}` | `getCity` | **Not implemented** (`Cities::find()` at `Cities.php:32` fakes it by scanning `all()`) | No |
| 3 | `GET /v1/cities/{cityId}/streets` | `getStreetsByCity` | `Resources/Streets.php:21` `byCity()` | No (module re-implements it: `Model/Api/AddressResolver.php:169`) |
| 4 | `GET /v1/offices` | `listOffices` | `Resources/Offices.php:21` `all()` | No (module re-implements it: `Model/Api/LocationsClient.php:159`) |
| 5 | `GET /v1/offices/{officeId}` | `getOffice` | **Not implemented** | No |
| 6 | `POST /v1/shipments/calculate` | `calculateShipment` | `Resources/Shipments.php:25` `calculate()` | **Yes** — `Model/Api/RateClient.php:81` |
| 7 | `POST /v1/shipments` | `createShipment` | `Resources/Shipments.php:39` `create()` | **Yes** — `Model/Api/ShipmentClient.php:93` |
| 8 | `GET /v1/shipments/{ref}/track` | `trackShipment` | `Resources/Shipments.php:58` `track()` | No |
| 9 | `POST /v1/shipments/track/bulk` | `bulkTrackShipment` | **Not implemented** | No |
| 10 | `POST /v1/shipments/{ref}/cancel` | `cancelShipment` | **Not implemented** | No |
| 11 | `GET /v1/shipments/{ref}/label` | `getShipmentLabel` | **Not implemented** | No |
| 12 | `GET /v1/shipment-statuses` | `listShipmentStatuses` | **Not implemented** | No |
| 13 | `GET /v1/additional-services` | `listAdditionalServices` | **Not implemented** | No |
| 14 | `GET /v1/payments/completed` | `listPayments` | **Not implemented** | No |
| 15 | `POST /v1/courier-requests` | `createCourierRequest` | **Not implemented** | No |
| 16 | `GET /v1/courier-requests/{requestNumber}` | `getCourierRequest` | **Not implemented** | No |
| 17 | `POST /v1/courier-requests/{requestNumber}/cancel` | `cancelCourierRequest` | **Not implemented** | No |

### 5.2 The three lists

**A. In the spec, implemented by the SDK, and used by the module (2):** `POST /v1/shipments/calculate`, `POST /v1/shipments`.

**B. In the spec, implemented by the SDK, but not used by the module (4):** `GET /v1/cities`, `GET /v1/cities/{cityId}/streets`, `GET /v1/offices`, `GET /v1/shipments/{ref}/track`. The first three are re-implemented in the module with raw Curl because the SDK's `all()` methods send no query parameters and therefore only ever return page 1 (header comment `Model/Api/LocationsClient.php:2-4`). The fourth is simply unused: the module has no tracking-refresh feature at all.

**C. In the spec, NOT implemented by the SDK at all (11):**

1. `GET /v1/cities/{cityId}` (`getCity`)
2. `GET /v1/offices/{officeId}` (`getOffice`)
3. `POST /v1/shipments/track/bulk` (`bulkTrackShipment`) — body `{references: string[]}`, 1..100 refs, always HTTP 200 with per-reference `found: true|false`, `meta.{requested,found,not_found}`. The only economical way to refresh many shipments under a 60/min limit.
4. `POST /v1/shipments/{ref}/cancel` (`cancelShipment`) — only valid while status is `shipment_registered`; returns `{reference_number, status, status_code, cancelled_at}`. The spec's own PHP sample advertises `$client->shipments()->cancel(...)`, which does not exist.
5. `GET /v1/shipments/{ref}/label` (`getShipmentLabel`) — query `download` (bool) and `format` (`default` 100x90 PDF, `pdf150` 100x150 PDF, `zpl300` raw ZPL as `text/plain`, `a4` PDF). Binary/text response, rate-limited to 10/min. The spec sample advertises `$client->shipments()->getLabel(...)`, which does not exist. Compounding this, `POST /v1/shipments` returns `label_pdf` (base64), `label_content_type` and `label_format` inline, and the SDK's `DTO/Response/Shipment.php` does not map any of them. **There is currently no supported way to obtain a shipping label through this SDK.**
6. `GET /v1/shipment-statuses` (`listShipmentStatuses`) — `data[{code, name}]`, e.g. `shipment_registered` / "Регистрирана пратка".
7. `GET /v1/additional-services` (`listAdditionalServices`) — `data[{code, name, description, input_type, option_group}]`. Documented codes include `sms_notification_receiver`, `requires_special_packaging`, `shipment_review`, `shipment_test_before_payment`, `declared_value`, **`cod_amount`**, **`cod_card_fee`**, `return_receipt`, `paper_return_receipt`, `service_return_documents`, `ID_verification_and_document_signature`. The module hardcodes this mapping at `Model/Rate/RateCollector.php:775-782` and `Model/ShipmentSender.php:564-571`.
8. `GET /v1/payments/completed` (`listPayments`) — required `from_date`/`to_date` (`YYYY-MM-DD`, max one calendar month apart). Returns `{count, rows[{waybill, external_reference, collected_date, paid_date, amount_eur}]}`. This is the COD payout reconciliation feed; nothing in the module consumes it, and no `CodPayment` DTO exists.
9. `POST /v1/courier-requests` (`createCourierRequest`) — returns 201.
10. `GET /v1/courier-requests/{requestNumber}` (`getCourierRequest`)
11. `POST /v1/courier-requests/{requestNumber}/cancel` (`cancelCourierRequest`)

### 5.3 Two SDK methods point at endpoints that do not exist

- `Resources/Offices.php:37` calls `GET /v1/offices/search`. No such operation. Reached by `search()`, `byCity()`, `byType()`, `lockers()`, `offices()`, `warehouses()`, `searchByName()`, `searchByCity()` — 8 public methods that will 404. `warehouses()` (line 103) also sends `type=warehouse`, outside the spec enum `office|locker`. `searchByName()` sends `limit` (line 122) where the spec expects `per_page`; `searchByCity()` sends `city` (line 137) where the spec expects `city_id`.
- `Resources/Streets.php:37` calls `GET /v1/streets/search`. No such operation. Reached by `search()`, `searchInCity()`, `searchByName()`.

That is 11 of the SDK's 24 public resource methods aimed at non-existent endpoints.

### 5.4 The DTO gap that directly causes the COD problem

`packages/pigeonexpress-php-sdk/src/DTO/Response/ShipmentCalculation.php` models a response shape the API does not return:

- `fromArray()` (lines 105-138) reads `shipping_price`/`delivery_fee`, `cod_fee`, `total_price`/`total`, `expected_delivery_days`, `fee_breakdown[]`/`service_fees[]`.
- The spec's `ShipmentCalculation` (lines 2838-2868) actually returns **`shipping_price`, `total_service_fees`, `total_price`, `service_type`, `currency`, `service_codes[]`** and nothing else. There is no `cod_fee`, no `fee_breakdown`, no `expected_delivery_days`.
- Consequence: `getCodFee()` always returns `null`, `hasCodFee()` always `false`, `getFeeBreakdown()` always `[]`, `getExpectedDeliveryDays()` always `0`, and **`total_service_fees` and `currency` are silently discarded** — there are no properties, no getters, and no `toArray()` keys for them.
- `FeeBreakdown.php` matches the spec's orphan `ServiceFee` schema (lines 2870-2879), which no endpoint response references. The class is never populated from a real payload.

The Magento side inherits all of this: `Model/Api/RateClient.php:85-91` reads `currency`, `expected_delivery_days` and `fee_breakdown` out of `toArray()`, so `currency` is always `null`, `estimated_delivery_days` always `0`, and `service_fees` always `null`. **The COD fee that the API actually charged, which is the `total_service_fees` value, never reaches Magento.** That is the technical reason the 2.5% constant exists.

Request-side mismatches are also present: `CalculateShipmentRequest.php:177-192` treats `service_codes` as a list (`$this->serviceCodes[] = $code`) while the spec requires a map of `code => value` (lines 2818-2831), and `setCodAmount()` (line 280) emits a **top-level** `cod_amount` key (line 370) instead of `service_codes.cod_amount`. `CreateShipmentRequest.php:195` / `:373` do the same. The Magento module works around the first of these by merging its own `service_codes` array over the DTO output (`Model/Api/ShipmentClient.php:82-84`) but not the second.

### 5.5 Escape hatch

All 11 missing endpoints are reachable today without new resource classes. `GuzzleHttpClient::request()` is private (line 100) but `HttpClientInterface` exposes public `get`/`post`/`put`/`delete` (lines 23, 34, 45, 55; implemented at `GuzzleHttpClient.php:51, 62, 73, 84`), and the client is retrievable via `PigeonExpress::getHttpClient()` (`PigeonExpress.php:77-80`). Paths resolve against `base_uri = <base>/v1/` (`GuzzleHttpClient.php:42`), so `$http->get('additional-services')`, `$http->post('shipments/track/bulk', [...])`, `$http->get('payments/completed', ['from_date'=>…,'to_date'=>…])` all work. Caveats: `Accept: application/json` and `Content-Type: application/json` are force-set for every request (`GuzzleHttpClient.php:151-152`), which is wrong for the binary label endpoint and for bodyless POSTs like `/cancel`; `Response::json()` (line 93) blindly decodes, so labels must be read via `Response::getBody()` (line 82); and there is still no retry or backoff. The Magento module does not use this escape hatch anywhere (grep for `getHttpClient` in the module: zero hits).

---

## 6. GAPS AND IMPROVEMENTS

Prioritised. P1 = correctness/money/security, ship first. P2 = important quality or capability. P3 = polish.

### P1

**1. COD fee is charged twice in dynamic pricing mode.** (M)
`Model/Rate/RateCollector.php:805-808` adds `service_codes.cod_amount` to the calculate payload whenever a configured COD payment method is selected. The API's `total_price` is defined as `shipping_price + total_service_fees`, and `getRatePrice()` returns `total_price` verbatim (`RateCollector.php:97`). The shipping rate therefore already contains the carrier's COD fee. `Model/Total/Quote/CodFee.php:55-61` then adds a second, locally invented 2.5% on top of the same shipping amount. In dynamic mode the customer is charged the COD fee twice. Why it matters: direct overcharge on every COD order. Fix jointly with item 2.

**2. The API's real COD/service fee is discarded; Magento invents its own.** (L)
See section 5.4. The API returns `total_service_fees`; the SDK DTO drops it; `Model/Api/RateClient.php:85-91` cannot see it. Work required: add `total_service_fees`, `currency`, `service_codes` and `service_type` to `ShipmentCalculation` (or bypass the DTO in `RateClient` and read the raw response), surface the figure through `RateClientInterface::calculate()`, then delete `FEE_PERCENT`/`MIN_FEE`. Why it matters: the hardcoded rate is wrong for any merchant on non-default terms and silently drifts when Pigeon Express changes pricing. Note: the in-flight `feature/api-driven-service-fees` branch has started on this (new `pigeonexpress_additional_service` table with `last_fee_amount`/`last_fee_basis`).

**3. `cod_amount` and `sms_notification` are sent at the top level on shipment creation, not inside `service_codes`.** (S)
`Model/ShipmentSender.php:167-169` sets `$payload['cod_amount']`; `Model/Api/ShipmentClient.php:212-214` forwards it to `CreateShipmentRequest::setCodAmount()`, which emits a top-level `cod_amount`. The spec requires `service_codes.cod_amount`. Likewise `$payload['sms_notification'] = true` (`ShipmentSender.php:176-178`) instead of `service_codes.sms_notification_receiver`. `ShipmentSender::buildServiceCodes()` (line 560) deliberately omits COD, unlike `RateCollector::buildServiceCodes()` (line 770) which includes it. Why it matters: if the API ignores the unknown top-level key, **the courier is never told to collect money** and the merchant is not paid. Highest-severity single-line bug in the module.

**4. Rate quote and created shipment use different package geometry.** (M)
`RateCollector::buildPackageDimensions()` (lines 352-373) **sums** L, W and H across every cart line; `ShipmentSender::buildPackageDimensions()` (lines 307-325) returns the **first** product with valid dimensions. `RateCollector::buildPackages()` (line 535) splits into 30 kg parcels; `ShipmentSender` always sends exactly one package (`'packages' => [$packageData]`, line 164). The quoted price and the invoiced price will differ, sometimes wildly. Separately, summing dimensions is physically meaningless: a cart of ten 10 cm cubes yields 100×100×100 and quickly hits the 200 cm clamp at `RateCollector.php:508-518`. Extract a single shared `PackageBuilder` service and give it a real (even naive) packing rule.

**5. `getAllowedMethods()` returns the wrong shape.** (S)
`Model/Carrier/PigeonExpress.php:196` returns `[$this->_code => implode(',', $enabled)]`, i.e. one entry whose value is the string `"address,office,aps"`. Magento's contract is `['methodCode' => 'Method Title']`. `Magento\Shipping\Model\Config\Source\Allmethods` and admin order creation both iterate this expecting code/title pairs. Why it matters: the carrier's methods are unusable in shipping-method-based cart rules, in `Magento_OfflineShipping` conditions, and in the admin "Create New Order" shipping selector.

**6. COD fee is folded into `shipping_amount` after tax, corrupting totals.** (M)
`CodFee` runs at `sort_order=450` (`etc/sales.xml:8`), i.e. after `shipping` (250) and after tax. It mutates `shipping_amount`, `base_shipping_amount`, and `total_amount('shipping')` (`CodFee.php:58-61`) but never touches `shipping_incl_tax` / `base_shipping_incl_tax`. The resulting order has `shipping_incl_tax < shipping_amount`, which breaks invoices, credit memos, and any tax reporting. `fetch()` returns `[]` (line 69), so the customer never sees a COD line item either. Also `etc/sales.xml` only registers the collector for the `quote` section — there is no `order`, `invoice` or `creditmemo` total, so `sales_order.pigeonexpress_cod_fee` (written at `Plugin/Quote/ToOrderPlugin.php:20`) is never read back by anything. Emit a proper separate total with `fetch()`, register it for order/invoice/creditmemo, and stop mutating shipping.

**7. `SetLocation` accepts unvalidated location data and writes it to the quote.** (S)
`Controller/Checkout/SetLocation.php:68-103` takes `location_id`, `location_name`, `location_address`, `location_city`, `location_postcode` straight from POST and writes them onto the quote shipping address and into `pigeonexpress_quote_address` without verifying that `location_id` corresponds to an active row in `pigeonexpress_office` / `pigeonexpress_aps`, and without checking that the type matches the selected shipping method. A crafted request can set an arbitrary city/postcode on the quote (changing the rate) or store a location id that later fails to resolve at order placement (`ShipmentSender.php:497-500` throws). Validate against `LocationSearchInterface` / `LocationToApiIdResolver` before persisting.

**8. Street resolution is unfiltered and unpaginated, so it usually fails for real cities.** (S)
`Model/Api/AddressResolver::searchStreetId()` (lines 163-188) issues `GET /v1/cities/{id}/streets` with no `?name=` filter and no pagination, then does an exact lowercase match in PHP over page 1 only. The spec supports `name` (min 2 chars, with Latin→Cyrillic transliteration) plus `page`/`per_page`. For Sofia or Plovdiv the correct street is almost never in the first 100 rows, so `street_id` is `null` and the module silently falls back to `additional_info` (line 98). Failures are swallowed entirely (lines 181-185). Pass `name`, honour pagination, and log a warning when nothing matches.

**9. `cod_card_fee` can never be enabled; `shipment_review` can never be sent.** (S)
`RateCollector.php:776` and `ShipmentSender.php:565` map config code `pos_payment` → `cod_card_fee`, read via `ConfigProvider::isAdditionalServiceEnabled()` from `carriers/pigeonexpress/service_pos_payment`. That field does not exist in `etc/adminhtml/system.xml` and has no default in `etc/config.xml`. Similarly `Model/Config/Source/ReviewAndTest.php:19-20` offers only `no`/`test`, while `RateCollector.php:792` also branches on `'review'` and `shipment_review` is never emitted. Either add the fields or delete the dead mappings.

### P2

**10. Adopt `GET /v1/additional-services` as the source of truth for service codes.** (M)
The mapping tables at `RateCollector.php:775-782` and `ShipmentSender.php:564-571` are duplicated verbatim in two files and hardcode ten API codes plus their mutual-exclusion semantics (`option_group`). The endpoint returns `code`, `name`, `description`, `input_type` and `option_group` and would let the admin form be generated. Neither the SDK nor the module touches it. This is the single largest unused capability in the spec and the natural companion to item 2.

**11. No label retrieval.** (M)
`GET /v1/shipments/{ref}/label` (formats `default`, `pdf150`, `zpl300`, `a4`) is unimplemented in the SDK and unused in the module, and `POST /v1/shipments` returns `label_pdf` inline which the SDK's `Shipment` DTO drops. A merchant currently cannot print a waybill from Magento at all. Store the base64 from the create response and add a "Print label" admin action.

**12. No shipment cancellation.** (S)
`POST /v1/shipments/{ref}/cancel` is unimplemented. Cancelling a Magento order leaves a live Pigeon Express shipment. Wire it to `sales_order_payment_cancel` / an admin button, guarded on status `shipment_registered`.

**13. No tracking refresh.** (M)
`pigeonexpress_shipment.status` is written once at creation (`ShipmentSender.php:207`) and never updated. `GET /v1/shipments/{ref}/track` exists in the SDK but is never called; `POST /v1/shipments/track/bulk` (up to 100 refs per call) is not in the SDK at all. Add a cron job using bulk tracking, map codes via `GET /v1/shipment-statuses`, and write tracking numbers into `sales_shipment_track` so the customer sees them in "My Orders".

**14. No COD payout reconciliation.** (M)
`GET /v1/payments/completed` returns `{waybill, external_reference, collected_date, paid_date, amount_eur}`. Nothing consumes it. Merchants have no way to reconcile COD collections against orders inside Magento. Note the module also never sets `external_reference` on created shipments (`ShipmentSender.php:157-165` omits it), which is the field that would let a payout row be matched back to a Magento order. **Setting `external_reference` to the order increment id is a one-line change and a prerequisite for any reconciliation work.**

**15. No courier pickup requests.** (M)
All three `/v1/courier-requests` operations are unimplemented. A merchant with `pickup_type=address` has no way to book a collection.

**16. No caching of rate API responses; up to 9 EAV collection loads per rate request.** (M)
With all three delivery types on `dynamic`, one `collectRates()` issues three sequential `POST /v1/shipments/calculate` calls, each with up to three retries at 1200 ms (`RateClient.php:28,31`). None of the responses is cached. Within a single delivery type, `cartHasDimensions()` (line 433), `buildPackageDimensions()` (line 341) and `calculateCartWeight()` (line 621) each build and `load()` their own product collection — three loads per type, nine per request. With logging enabled, `RateCollector.php:78` calls `calculateCartWeight()` a second time purely to populate a log array, adding three more. Magento calls `collectRates` on address change, method selection and (via `setShippingInformation`) payment-method change. Add a short-TTL cache keyed on the payload hash, and load product dimension/weight data once per request.

**17. `fetchCities()` has no retry and no throttle while `fetchByType()` has both.** (S)
`Model/Api/LocationsClient::fetchCities()` (lines 101-119) calls `doGetFull()` directly, skipping `getWithRetry()` and the 1 s `PAGE_DELAY_US`. With roughly 5,275 cities at 100 per page that is 53 rapid requests against a documented 60/min limit. A single 429 throws and aborts `syncAll()` (`Model/LocationSync.php:132`) *after* offices and APS have already succeeded, leaving a partial sync with no record of what completed.

**18. No last-sync timestamp, no staleness signal, no admin visibility.** (S)
See section 3.4. Store `last_sync_at` / `last_sync_status` / row counts in config (or a small status table), render them next to the sync button, and warn when the catalogue is older than N days. Cheap, and it is the first question any merchant asks.

**19. Sync throws away city and postcode, then re-derives them with a JOIN.** (S)
`Model/LocationSync::applyApiData()` explicitly sets `setCity(null)` (line 208) and `setPostcode(null)` (line 210), even though the API's office payload contains a nested `city` object and a `postal_code` (spec `Office` schema). Consequences: the `PIGEONEXPRESS_OFFICE_CITY` index (`db_schema.xml:28-30`) is useless; the `main_table.city` term in the autocomplete filter (`LocationSearch.php:106`) can never match; `Model/Config/Source/PickupOffice.php:46-47` never shows a city in the dropdown label; and every search pays for a LEFT JOIN (`LocationSearch.php:65-76`) to recover data that was already in hand.

**20. No tests of any kind.** (L)
There is no `Test/` directory, no `phpunit.xml`, no `phpcs.xml`, no `phpstan.neon`, and no CI configuration. For a module that moves money (COD amounts) and calls a third-party API, at minimum: unit tests for `CodFee`, `ShipmentSender::normalizePhone()`, `AddressResolver::extractStreetNameAndNumber()`, `RateCollector::buildServiceCodes()` and `buildPackages()`; and integration tests for the quote→order extension-attribute round trip. This is the highest-leverage single investment, because items 1-6 are all silent-wrong-number bugs that a test would have caught.

**21. Unconditional debug logging on hot paths, into the wrong log.** (S)
`etc/di.xml:6-73` rebinds `Psr\Log\LoggerInterface` to the PE handler only for a named list of types. The plugins are not on that list, so they log to `var/log/system.log`, and they do so without checking `enable_logging`:
`Plugin/Quote/QuoteAddressLoadPlugin.php:68-71` (fires on **every** quote address load), `Plugin/Checkout/ShippingInformationManagementPlugin.php:71-75, 109-113, 116-120`, `Plugin/Checkout/ShippingInformationManagementValidationPlugin.php:47-51`, `Plugin/Quote/QuoteManagementPlugin.php:77-84`, `Controller/Checkout/SetLocation.php:120-125`, `Observer/Sales/OrderPlaceAfter.php:48-51`, `Model/Cron/SyncLocations.php:47`. Also note `Logger/Handler.php:21` sets `$loggerType = Logger::INFO`, so the PE log captures everything at INFO and above regardless of the config flag.

**22. `console.log` left throughout production JavaScript.** (S)
`view/frontend/web/js/view/checkout/shipping/location-autocomplete.js:226, 234, 239, 246, 258, 268`; `.../shipping-save-processor/default-mixin.js:43, 51, 60`; `.../payload-mixin.js:14, 21, 34`; `.../bss-validate-mixin.js:25, 54, 63`; `view/checkout/shipping/address-visibility.js:62, 66, 79, 87, 91, 114, 119, 135, 140, 164`; `action/select-payment-method-mixin.js:18`. The payload logs include the customer's selected address data.

**23. Reflection into a private Magento core property.** (S)
`Plugin/Sales/Order/Email/OrderSenderPlugin.php:33-36` uses `ReflectionProperty` to reach `Magento\Sales\Model\Order\Email\Sender::$templateContainer`. This breaks on any core refactor and is a hard blocker for Marketplace/EQP submission. Replace with a plugin on `Magento\Sales\Model\Order\Email\Container\Template::getTemplateId` or `setTemplateId`.

**24. Inline `<script>` in an admin config block (CSP).** (S)
`Block/Adminhtml/System/Config/SearchableSelect.php:37-62` emits a raw `<script>` element. Magento 2.4 ships `Magento_Csp`, and adminhtml runs in report-only by default but is expected to move to enforce. Move the behaviour to a RequireJS module and pass data via `data-mage-init`. The same heredoc interpolates `$placeholder` (a `Phrase`) directly into an HTML attribute at line 31 with no `escapeHtmlAttr`, and `$selectHtml` is injected unescaped at line 35 (the latter is trusted core output, but the pattern is fragile).

**25. Dead JavaScript, including three of the four COD files.** (S)
`view/frontend/web/js/view/checkout/summary/cod-fee.js`, `view/frontend/web/template/checkout/summary/cod-fee.html`, `view/frontend/web/js/view/checkout/summary/grand-total-mixin.js`, `view/frontend/web/js/model/cod-fee.js`, `view/frontend/web/js/payment-cod-recalc.js`, and `view/frontend/web/js/action/select-payment-method-mixin.js` are referenced by nothing: not `requirejs-config.js`, not any layout XML, not `LayoutProcessorPlugin`. Note that `grand-total-mixin.js:12` would **double-count** the fee if it were ever wired up, because `CodFee` already merges it into the server-side shipping amount. And `payment-cod-recalc.js:17-29` re-implements COD detection with string heuristics (`cod`, `cashondelivery`, `_cod`, `cod_`) that ignore the `cod_payment_methods` admin setting entirely. Delete or wire up deliberately.

**26. Orphan rows in `pigeonexpress_quote_address` accumulate forever.** (S)
The FK is intentionally dropped (`Setup/Patch/Schema/DropQuoteAddressForeignKey.php`), so Magento's quote cleanup never cascades. On a busy store this table grows unbounded. Add a cron job that deletes rows whose `address_id` no longer exists in `quote_address`.

**27. Full request/response JSON with customer PII stored indefinitely.** (S)
`pigeonexpress_shipment.payload` and `.response` (`db_schema.xml:128-129`) hold receiver name, phone and address in clear text with no retention policy and no integration with Magento's `Magento_GdprEraser`-style flows. Add a retention cron and redact phone/address, or store only a correlation id.

**28. Internal interfaces are not service contracts.** (M)
`Api/*Interface.php` pass and return raw `array` (e.g. `RateClientInterface::calculate(array $payload, int $storeId): array`), and there are no `Api/Data/*Interface` DTOs, no repositories for `Office`/`Aps`/`City`/`Shipment`, and no `etc/webapi.xml`. Persistors (`Model/QuoteAddressLocationPersistor.php`, `Model/OrderAddressLocationPersistor.php`) write raw SQL through `ResourceConnection`, bypassing the model layer they otherwise define. A merchant or an OMS cannot query PE shipments over REST. Introduce `ShipmentRepositoryInterface` + `Api/Data/ShipmentInterface` at minimum.

**29. SDK client constructed inside the retry loop rather than injected.** (S)
`Model/Api/RateClient.php:72` and `Model/Api/ShipmentClient.php:73` each do `new PigeonExpress($apiKey, $apiSecret, $options)` inside the `for` loop, so a fresh Guzzle client (and a fresh connection pool) is built per attempt, per delivery type, per request. Introduce a `PigeonExpressClientFactory` with per-store memoisation. This also makes the clients testable, which today they are not.

**30. Retry policy retries non-retryable errors.** (S)
`RateClient.php:92-112`: the `SdkValidationException` and `SdkException` branches record the exception but **do not sleep**, so a 422 is retried three times back to back with zero delay, then reported. `ShipmentClient.php:115-125` gets this right (`break` on validation). Align them: never retry 4xx, apply exponential backoff and honour `Retry-After` on 429 (the SDK surfaces it via `RateLimitException::getRetryAfter()` but nobody reads it). Note the SDK itself has zero retry logic, a single 30 s `timeout` and no `connect_timeout` (`packages/pigeonexpress-php-sdk/src/Configuration.php:20`, `HttpClient/GuzzleHttpClient.php:42-44`).

### P3

**31. i18n coverage is thin and single-language.** (S)
`i18n/en_US.csv` has 41 rows. The module contains far more translatable strings: every `system.xml` `<comment>`, every `ApiException` message (`RateCollector.php:146, 171, 255, 268, 325`; `AddressResolver.php:60, 154`; `LocationsClient.php:84, 139, 266, 270`), every `LocalizedException` in `ShipmentSender.php` (lines 86, 94, 100, 116, 137, 439, 447, 499) and `QuoteManagementPlugin.php` (lines 88, 94, 97), and all admin messages in the controllers. There is no `bg_BG` file despite the carrier being Bulgarian. Two source strings are partly Bulgarian in `en_US`: `system.xml:97` ("Разширена застраховка") and the file header of `view/frontend/web/js/payment-cod-recalc.js:6` plus comments in `address-visibility.js:72, 103, 113, 125` (Russian).

**32. `'UA'` hardcoded as the default country fallback.** (S)
`view/frontend/web/js/view/checkout/shipping/location-autocomplete.js:199` and `view/frontend/web/js/view/checkout/shipping/address-visibility.js:44` both fall back to `'UA'` (Ukraine) when `window.checkoutConfig.defaultCountryId` is absent, in a module whose entire address model assumes Bulgaria (`LocationSync.php:209` hardcodes `'BG'`).

**33. `Schedule` is a state-changing GET.** (S)
`Controller/Adminhtml/Sync/Schedule.php:16` implements `HttpGetActionInterface` while inserting a `cron_schedule` row (lines 72-77). The admin secret key provides de-facto CSRF cover, and the block comment at `SyncButton.php:21` says as much, but Magento treats `HttpGetActionInterface` controllers as intentionally CSRF-exempt. Convert to POST with a form key, as the "Send to Pigeon Express" form already does (`view/adminhtml/templates/delivery/info.phtml:65-66`).

**34. `SetLocation` relies on Magento's XHR CSRF bypass.** (S)
`Magento\Framework\App\Request\CsrfValidator::validateRequest()` skips form-key validation when `$request->isXmlHttpRequest()` is true. `Controller/Checkout/SetLocation.php` sends no form key and implements neither `CsrfAwareActionInterface` nor any check; it works only because jQuery sets `X-Requested-With`. Cross-origin exploitation is impractical (that header triggers a CORS preflight), but the endpoint should send a form key or implement `CsrfAwareActionInterface` explicitly rather than depend on an accident.

**35. `SetLocation` calls `$quote->save()` directly.** (S)
Line 118. `Magento\Quote\Api\CartRepositoryInterface::save()` is the supported path; direct model save bypasses the repository's `beforeSave` chain and is deprecated style.

**36. `LayoutProcessorPlugin` walks a deep array by reference with no guards.** (S)
`Plugin/Checkout/LayoutProcessorPlugin.php:29-30` does `$children = &$jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']['shippingAddress']['children'];`. PHP auto-vivifies missing keys, so on a theme or third-party checkout with a different tree the plugin silently creates a phantom branch and the components never render, with no error. Add `isset()` guards and log when the expected structure is absent.

**37. `ConfigProvider::getCodPaymentMethods()` does not trim or filter.** (S)
Line 424: `explode(',', (string) $value)` with no `array_filter`/`trim`. A stray comma or a whitespace-padded value produces `['']` or `[' checkmo']`, and the strict `in_array(..., true)` comparisons at `CodFee.php:51`, `RateCollector.php:832` and `ShipmentSender.php:418` will then silently fail to match.

**38. The declared config ACL resource is orphaned.** (S)
`etc/acl.xml:22-29` declares `PigeonExpress_Shipping::config_pigeonexpress`, but the settings live under the core `carriers` section (`system.xml:9`), which the module cannot re-attribute. Grep confirms only `PigeonExpress_Shipping::sync` (`Schedule.php:48`) and `PigeonExpress_Shipping::send_shipment` (`Send.php:16`) are actually enforced. Either move the settings into an own section that can carry the resource, or drop the unused declaration.

**39. `registration.php` registers a prepended global autoloader.** (S)
Lines 19-27 register an `spl_autoload_register(..., prepend: true)` that intercepts every class whose FQCN starts with `PigeonExpress\`, including the module's own `PigeonExpress\Shipping\*` classes, doing a `file_exists()` per miss. It exists to work around a broken `vendor/` (comment at line 16). With `pigeonexpress/php-sdk: ^1.0` already in `composer.json:13`, remove it.

**40. No admin grid for `pigeonexpress_shipment`.** (M)
There is no `view/adminhtml/ui_component/*.xml` and no menu entry. Shipments are only visible one at a time, embedded in the order view (`view/adminhtml/templates/delivery/info.phtml:41-61`). No listing, no search by reference number, no bulk send, no bulk label print.

**41. Hidden but active country restriction fields.** (S)
`system.xml:50-59` declares `sallowspecific` and `specificcountry` with all three `showIn*` flags set to `0`, yet `AbstractCarrier::checkAvailableShipCountries()` still honours them. A value written by an import or by `config:set` is invisible and un-debuggable in the admin UI. Either show them or remove them and hard-code the behaviour.

**42. Bulgarian-specific logic is not gated by country.** (S)
`ShipmentSender::normalizePhone()` (lines 527-553) assumes `+359` for any 9-digit or `0`-prefixed input, and `LocationSync::applyApiData()` (line 209) writes `country = 'BG'` unconditionally. This is presumably correct today, but it should be an explicit, documented constraint (and ideally driven from the shipping address country) rather than an implicit one.

---

## 7. KNOWN BUG CONTEXT — every site of the hardcoded 2.5% / 0.80 COD fee

The canonical definition is `Model/Total/Quote/CodFee.php:15-16`. Below is every place in the module that states, derives from, duplicates or structurally depends on it. Absolute paths are given once; all are under
`/home/pigeonexpress/web/pigeonexpress.softart.work/public_html/magento_app/app/code/PigeonExpress/Shipping/`.

### 7.1 The value itself (PHP)

| # | File : line | Content |
|---|---|---|
| 1 | `Model/Total/Quote/CodFee.php:15` | `public const FEE_PERCENT = 0.025;` — the source of truth |
| 2 | `Model/Total/Quote/CodFee.php:16` | `public const MIN_FEE = 0.80;` |
| 3 | `Model/Total/Quote/CodFee.php:55` | `$fee = max(round($quote->getSubtotal() * self::FEE_PERCENT, 2), self::MIN_FEE);` — the only computation |
| 4 | `Model/Total/Quote/CodFee.php:58-61` | The fee is merged into `shipping_amount` / `total_amount('shipping')`, so removing it changes how the shipping total is built |
| 5 | `Model/Total/Quote/CodFee.php:64` | `$address->setData(self::CODE, $fee)` — the carrier for the value downstream |
| 6 | `Model/CheckoutConfigProvider.php:31` | `'cod_fee_percent' => CodFee::FEE_PERCENT * 100,` — exports `2.5` into `window.checkoutConfig.pigeonexpress` |
| 7 | `Model/CheckoutConfigProvider.php:9` | `use PigeonExpress\Shipping\Model\Total\Quote\CodFee;` — the import that creates the coupling |

### 7.2 Prose that states the value (XML / PHPDoc / Markdown)

| # | File : line | Content |
|---|---|---|
| 8 | `etc/adminhtml/system.xml:69` | `<comment>Payment methods that trigger a COD fee (2.5% of subtotal, minimum 0.80). The fee is added to the shipping cost.</comment>` — merchant-facing |
| 9 | `Api/ConfigInterface.php:247` | `* Get list of payment method codes that trigger the COD fee (2.5% of subtotal).` |
| 10 | `etc/db_schema.xml:118` | Column comment `Pigeon Express COD Fee (2.5% of subtotal)` on `sales_order.pigeonexpress_cod_fee`. **Already changed in the uncommitted working tree** to `Pigeon Express COD Fee as returned by the API for this order`. |
| 11 | `README.md:362` | `\| `sales_order` (column) \| `pigeonexpress_cod_fee` — COD fee amount stored with the order \|` (no percentage, but documents the column) |

### 7.3 JavaScript (all of it currently dead code — see item 25)

| # | File : line | Content |
|---|---|---|
| 12 | `view/frontend/web/js/view/checkout/summary/cod-fee.js:2` | Header comment `show COD fee (2.5% of subtotal) in checkout summary` |
| 13 | `view/frontend/web/js/view/checkout/summary/cod-fee.js:29` | `var feePercent = this.cod_fee_percent \|\| 2.5;` — a second hardcoded literal, used when the config provider value is absent |
| 14 | `view/frontend/web/js/view/checkout/summary/cod-fee.js:47` | `totals.getSegment('pigeonexpress_cod_fee')` — always `null` because `CodFee::fetch()` returns `[]` |
| 15 | `view/frontend/web/js/view/checkout/summary/cod-fee.js:52-55` | `fee = Math.round(subtotal * feePercent) / 100;` — the client-side duplicate of the formula, **and it omits the 0.80 minimum entirely**, so JS and PHP disagree for any subtotal below 32.00 |
| 16 | `view/frontend/web/js/view/checkout/summary/cod-fee.js:57, 61` | `codFeeModel.fee(fee)` / `codFeeModel.fee(0)` |
| 17 | `view/frontend/web/template/checkout/summary/cod-fee.html:3` | `translate="'COD Fee (2.5%)'"` — the percentage baked into a UI label and into the translation key |
| 18 | `view/frontend/web/js/model/cod-fee.js:8` | `fee: ko.observable(0)` — the shared observable the value flows through |
| 19 | `view/frontend/web/js/view/checkout/summary/grand-total-mixin.js:12` | `return this._super() + codFeeModel.fee();` — would double-count, since `CodFee` already folds the fee into shipping |
| 20 | `view/frontend/web/js/payment-cod-recalc.js:17-29` | `isCodMethod()` — an independent, hardcoded COD-detection heuristic (`cod`, `cashondelivery`, `_cod`, `cod_`, trailing `cod`) that ignores the `cod_payment_methods` admin setting |
| 21 | `view/frontend/web/js/action/select-payment-method-mixin.js:11-25` | Re-sends shipping information on payment change specifically so the COD surcharge is recollected (header comment lines 1-4) |

### 7.4 Wiring and persistence that exists only to carry the value

| # | File : line | Content |
|---|---|---|
| 22 | `etc/sales.xml:6-8` | Registers `CodFee` as a quote total at `sort_order=450` |
| 23 | `etc/di.xml:139-142` | Registers `ToOrderPlugin` on `Magento\Quote\Model\Quote\Address\ToOrder` |
| 24 | `Plugin/Quote/ToOrderPlugin.php:9, 18-21` | `use ... CodFee;` and `$result->setData(CodFee::CODE, round((float) $fee, 4));` — copies the value onto the order |
| 25 | `Plugin/Quote/QuoteManagementPlugin.php:43-48` | Forces `collectTotals()` before submit specifically so "COD fee is always applied with the correct payment method" |
| 26 | `etc/db_schema.xml:117` | The `sales_order.pigeonexpress_cod_fee` column itself |
| 27 | `etc/db_schema_whitelist.json:101` | `"pigeonexpress_cod_fee": true` |

### 7.5 The API-driven half of COD (what should replace 7.1-7.3)

| # | File : line | Content |
|---|---|---|
| 28 | `Model/Rate/RateCollector.php:804-808` | `$serviceCodes['cod_amount'] = $codAmount;` — tells the API to price COD, which is why the result is double-charged |
| 29 | `Model/Rate/RateCollector.php:817-839` | `getCodAmountForCalculation()` — reads the checkout-session quote's subtotal when a configured COD method is selected |
| 30 | `Model/Rate/RateCollector.php:97` | `return (float) ($calc['total_price'] ?? 0.0);` — `total_price` already includes `total_service_fees`, i.e. the API's own COD fee |
| 31 | `Model/Api/RateClient.php:85-91` | The response mapping that drops `total_service_fees`; `service_fees` here is always `null` (see 5.4) |
| 32 | `Model/ShipmentSender.php:142` and `:167-169` | `$codAmount = $this->detectCodAmount($order);` then `$payload['cod_amount'] = $codAmount;` — **top level, not inside `service_codes`** |
| 33 | `Model/ShipmentSender.php:409-425` | `detectCodAmount()` — returns `round($order->getSubtotal(), 2)` for configured COD methods |
| 34 | `Model/Api/ShipmentClient.php:212-214` | `$request->setCodAmount((float) $payload['cod_amount']);` |
| 35 | `Model/Rate/RateCollector.php:776` and `Model/ShipmentSender.php:565` | `'pos_payment' => 'cod_card_fee',` — the COD card-fee service, unreachable because `service_pos_payment` is not a config field |
| 36 | `Model/Config/ConfigProvider.php:414-425` and `Api/ConfigInterface.php:252` | `getCodPaymentMethods()` — the one piece of COD configuration that is genuinely admin-driven |
| 37 | `etc/config.xml:23` | `<cod_payment_methods></cod_payment_methods>` default |
| 38 | `Model/Total/Quote/CodFee.php:40-53` | The eligibility gate (PE carrier prefix + configured COD payment method) that any replacement must preserve |

### 7.6 Already touched by the in-flight `feature/api-driven-service-fees` work

- `etc/db_schema.xml:118` comment (item 10 above) — rewritten.
- `etc/db_schema.xml` — new `pigeonexpress_additional_service` table added with `last_fee_amount`, `last_fee_basis`, `fee_synced_at`.
- `Model/AdditionalService.php:29-30` — `public const CODE_COD_FEE = 'cod_fee';`
- `Model/AdditionalService.php:133` — comment: *"COD fee. Returns null when no API derived figure has ever been stored."*
- `Model/ResourceModel/AdditionalService.php` — new.

Everything in 7.1, 7.2 (items 8, 9, 11), 7.3 and 7.4 was still untouched at the time of reading.
