# Pigeon Express for WooCommerce: reference implementation analysis

Version analysed: **1.0.2** (`Plugin Name: Pigeon Express for WooCommerce`, DB version `1.0.0`).

**PLUGIN_ROOT** = `/home/pigeonexpress/web/pigeonexpress.softart.work/public_html/magento_app/pub/.temp_docs_ai_helpful/pigeon-express-for-woocommerce`

All file paths below are relative to PLUGIN_ROOT unless stated otherwise. OpenAPI spec referenced as
`/tmp/claude-1009/.../scratchpad/pigeonexpress-openapi.yaml`.

Size: 49 files, ~8.5k lines PHP + ~2k lines JS. Namespace `PigeonExpressWC\`, PSR-4 from `src/`.

---

## 1. API SURFACE

### 1.1 The single HTTP client

There is exactly one HTTP client: `src/Api/ApiClient.php`. Every outbound call in the plugin goes
through it. There is no vendored SDK (`vendor/autoload.php` is optional, `pigeon-express-for-woocommerce.php:27-46`
falls back to a hand-rolled autoloader). Transport is `wp_remote_request()` / `wp_remote_get()`.

Auth and base URL:

```php
// src/Api/ApiClient.php:322-330
private function build_headers(): array {
    return [
        'Content-Type' => 'application/json',
        'Accept'       => 'application/json',
        'X-API-Key'    => $this->api_key,
        'X-API-Secret' => $this->api_secret,
        'X-Platform'   => self::PLATFORM,   // 'wordpress_softart' (line 26)
    ];
}
```

```php
// src/Settings.php:36-53
public const URL_LIVE    = 'https://api.pigeonexpress.com/v1';
public const URL_SANDBOX = 'https://api-demo.pigeonexpress.com/v1';
public static function get_base_url(): string {
    return self::is_sandbox() ? self::URL_SANDBOX : self::URL_LIVE;
}
```

Note the `/v1` is baked into the base URL constant, so endpoint constants are written without it.
Timeout is a hard 15 seconds (`ApiClient.php:22`, `private const TIMEOUT_SECONDS = 15`).

### 1.2 Endpoint constants

```php
// src/Api/ApiClient.php:13-20
public const ENDPOINT_CITIES       = '/cities';
public const ENDPOINT_CITY_STREETS = '/cities/%d/streets';
public const ENDPOINT_OFFICES      = '/offices';
public const ENDPOINT_SERVICES     = '/services';
public const ENDPOINT_CALCULATE    = '/shipments/calculate';
public const ENDPOINT_SHIPMENTS    = '/shipments';
public const ENDPOINT_TRACK        = '/shipments/%s/track';
public const ENDPOINT_CANCEL       = '/shipments/%s/cancel';
```

### 1.3 Call-by-call map

| # | Client method (file:line) | HTTP + path | Spec path (line) | Callers and purpose |
|---|---|---|---|---|
| 1 | `check_credentials_detailed()` `ApiClient.php:50-78` | `GET /cities/759` (hardcoded city id 759, line 52) | `/v1/cities/{cityId}` (spec:184) | `src/Admin/CredentialsValidator.php:73`. Cheapest possible authenticated probe after the merchant saves API key/secret. A 200 marks credentials valid. |
| 2 | `get_cities( int $page, ?string $name )` `ApiClient.php:83-91` | `GET /cities?page=&name=` | `/v1/cities` (spec:55), `name` = ILIKE search (spec:64) | `src/Admin/AjaxHandlers.php:446` (Select2 sender-city picker), `src/Admin/OrderMetaBox.php:381` (auto-resolve the order's billing city to a Pigeon city id). |
| 3 | `get_streets( int $city_id, int $page, ?string $name )` `ApiClient.php:96-107` | `GET /cities/{id}/streets?page=&city_id=&name=` | `/v1/cities/{cityId}/streets` (spec:282) | `src/Admin/AjaxHandlers.php:103` (Select2 street picker in Send From + order metabox), `src/Admin/OrderMetaBox.php:459` (auto-resolve billing street to a street id). Note the redundant `city_id` query param on line 99, the spec only defines `name`/`page`/`per_page` for this route. |
| 4 | `get_offices( ?int $city_id, int $page )` `ApiClient.php:112-114` | `GET /offices?type=office&page=` | `/v1/offices` (spec:413), `type` enum `office|locker` (spec:437) | `src/Cron/LocationSync.php:136`. Bulk sync of counter offices. |
| 5 | `get_aps_locations( ?int $city_id, int $page )` `ApiClient.php:119-121` | `GET /offices?type=locker&page=` | same as above | `src/Cron/LocationSync.php:137`. Bulk sync of parcel lockers. The plugin normalises the API's `locker` to its own `aps` vocabulary (`src/Repositories/LocationRepository.php:26-31`). |
| 6 | `get_services()` `ApiClient.php:126-128` | `GET /services` | **NOT IN SPEC.** The spec's equivalent is `GET /v1/additional-services` (spec:1800) | `src/Cron/LocationSync.php:81`. See §1.4. |
| 7 | `calculate_price( array $params )` `ApiClient.php:133-135` | `POST /shipments/calculate` | `/v1/shipments/calculate` (spec:754), body = `CalculateShipmentRequest` (spec:2771) | `src/ShippingMethods/AbstractPigeonMethod.php:235`. The live checkout quote. |
| 8 | `create_shipment( array $params )` `ApiClient.php:140-142` | `POST /shipments` | `/v1/shipments` (spec:925), body = `CreateShipmentRequest` (spec:2881) | `src/Labels/LabelService.php:126`. Creates the consignment and returns the base64 label PDF. |
| 9 | `cancel_shipment( string $reference )` `ApiClient.php:154-156` | `POST /shipments/{ref}/cancel` | `/v1/shipments/{referenceNumber}/cancel` (spec:1464) | `src/Labels/LabelService.php:232`. |
| 10 | `get_shipment_tracking( string $reference )` `ApiClient.php:147-149` | `GET /shipments/{ref}/track` | `/v1/shipments/{referenceNumber}/track` (spec:1138) | **DEAD CODE.** Grep across the whole plugin finds no caller. Tracking is defined but never surfaced anywhere in the UI. |
| 11 | `paginate( string $endpoint, array $params )` `ApiClient.php:163-186` | generic paged GET walker | n/a | **DEAD CODE.** `LocationSync` implements its own paging loop instead (`src/Cron/LocationSync.php:134-172`). |
| 12 | `check_credentials(): bool` `ApiClient.php:38-42` | wrapper over #1 | n/a | **DEAD CODE.** Only `check_credentials_detailed()` is used. |

### 1.4 Endpoints in the spec that the plugin does NOT use

Relevant for the Magento port, these are available but unused:

- `GET /v1/additional-services` (spec:1800). The plugin instead calls `/services` (`ApiClient.php:16`).
  This looks like a legacy/renamed route. **Any Magento implementation should target `/additional-services`**,
  which is the documented one and returns `code`, `name`, `description`, `input_type` (`checkbox|text|select`),
  and `option_group` (spec:3308-3356).
- `GET /v1/shipments/{ref}/label` (spec:1578). The plugin never re-fetches a label; it depends entirely on the
  `label_pdf` field of the create response and warns the merchant to cancel+recreate if the disk write fails
  (`src/Labels/LabelService.php:164-181`).
- `POST /v1/shipments/track/bulk` (spec:1298).
- `GET /v1/shipment-statuses` (spec:1740).
- `GET /v1/payments/completed` (COD payout reconciliation, spec:1908).
- `POST /v1/courier-requests` and its get/cancel siblings (spec:2011, 2188, 2232).
- `GET /v1/offices/{officeId}` (spec:624).

### 1.5 Error handling and logging

`ApiClient::request()` (`ApiClient.php:191-268`):

- Missing key or secret short-circuits into a `WP_Error` before any HTTP call (`lines 192-197`).
- Treats `status >= 400` **or** `$parsed['success'] === false` as an error (`line 243`).
- Flattens `errors{}` / `message` into a single human string (`flatten_error()`, `lines 345-371`).
- Every request logs one summary line; non-GET requests log the full outbound body and full response body
  (`lines 217, 234, 248, 264`), with the base64 label PDF replaced by a size marker (`redact_for_log()`, `lines 279-291`).
- `encode_body()` (`lines 306-320`) temporarily sets `serialize_precision = -1` so monetary floats such as
  `cod_amount` serialise as `64.06` not `64.06000000000000227`. This is a real-world bug fix worth carrying over.
- Logging goes to the WooCommerce logger with source `pigeon-express` (`src/Util/DebugLog.php:35, 60`), gated
  **only** by the plugin's own Debug log checkbox, deliberately not by `WP_DEBUG_LOG` (`DebugLog.php:41-46`).
  The first line per request is an `ENV` stamp with plugin/WC/WP/PHP versions, HPOS on/off, sandbox on/off and
  default who-pays (`DebugLog.php:87-96`).

---

## 2. DYNAMIC VS STATIC: where every displayed or charged value comes from

This is the core question. Summary table first, evidence below.

| Data | Source | Refresh |
|---|---|---|
| Shipping price at checkout | **LIVE per request** (`POST /shipments/calculate`) | Every rate calculation, subject to WC's own shipping-rate session cache |
| COD fee / card fee | **LIVE, folded into the returned price by the API.** Never computed locally | same as above |
| Any additional-service fee | **LIVE, folded into the returned price by the API.** Never computed locally | same as above |
| Fixed price override | **Static admin setting** per shipping-method instance | manual |
| Free shipping threshold | **Static admin setting** per shipping-method instance | manual |
| Offices | **DB table, refreshed on a schedule** | daily cron + manual force sync |
| Parcel lockers (APS) | **DB table, refreshed on a schedule** | daily cron + manual force sync |
| Cities | **LIVE per keystroke** (admin Select2 only). Never stored | per AJAX request |
| Streets | **LIVE per keystroke** (admin Select2 only). Cached per order on the shipping line | per AJAX request; per-order cache keyed by address hash |
| Additional-service definitions (codes + labels) | **wp_options blob, refreshed on the same daily sync** | daily cron + manual force sync |
| Which services apply to which delivery method | **HARDCODED PHP constants** in the three method classes | code change only |
| Fallback service labels | **HARDCODED** array, used only before the first sync | code change only |
| Default box dimensions, default weight, weight/dimension caps | **HARDCODED** with `apply_filters` escape hatches | code change or filter |

### 2.1 Shipping price: fully dynamic

`src/ShippingMethods/AbstractPigeonMethod.php:147-193` is the decision tree:

```php
$fixed_price = (float) $this->get_option( 'fixed_price', 0 );
$threshold   = (float) $this->get_option( 'free_shipping_over', 0 );
$cart_total  = isset( $package['contents_cost'] ) ? (float) $package['contents_cost'] : 0.0;
$is_free     = ( $threshold > 0 && $cart_total >= $threshold ) || $this->cart_has_free_shipping_coupon();

if ( $is_free ) {
    $quote['cost'] = 0.0;
} elseif ( $fixed_price > 0 ) {
    $quote['cost'] = $fixed_price;
} else {
    $quote = $this->quote_from_api( $package );   // live API call
}
```

The API branch reads exactly one field:

```php
// src/ShippingMethods/AbstractPigeonMethod.php:249
$result['cost'] = isset( $response['data']['total_price'] ) ? (float) $response['data']['total_price'] : 0.0;
```

The spec's `ShipmentCalculation` (spec:2838-2867) also returns `shipping_price`, `total_service_fees`,
`currency` and the echoed `service_codes[]`. **The plugin ignores all of them and takes only `total_price`.**
That is the entire pricing model: whatever the carrier says the total is, that is what the customer is charged.
There is no local arithmetic on top of it anywhere in the codebase.

Failure modes, all non-fatal (`quote_from_api()`, `AbstractPigeonMethod.php:209-253`):

- Credentials not validated: cost stays `0.0`, `errors = ['no_credentials']`, `validated = false`.
- Body cannot be built (no pickup configured, no destination yet): cost `0.0`, `errors = ['missing_destination']`.
- API error: cost `0.0`, `errors = ['api_error']`, and a `wc_get_logger()->warning` with the full body
  (`log_quote_error()`, `lines 414-423`).

In all three cases **the rate is still added at cost 0**, so the method remains visible in checkout.
That flag is preserved into order meta and surfaced later in the order note as
`"price not validated by the carrier"` (`src/Admin/OrderNotes.php:73`).

### 2.2 Rate cache invalidation on payment-method change

WooCommerce caches shipping rates per package hash. Because the Pigeon quote differs between COD and non-COD,
the plugin injects the chosen payment method into the package so the hash changes:

```php
// src/Checkout/CheckoutIntegration.php:63-75
public static function attach_payment_method_to_packages( array $packages ): array {
    $chosen = (string) WC()->session->get( 'chosen_payment_method' );
    foreach ( $packages as $index => $package ) {
        $packages[ $index ]['pe_wc_chosen_payment_method'] = $chosen;
    }
    return $packages;
}
```

Classic checkout triggers `update_checkout` on payment-radio change (`assets/js/checkout-classic.js:337-351`).
Blocks checkout has no `chosen_payment_method` session key at all, so there is a dedicated AJAX bridge
`pigeon_express_wc_set_payment_method` that writes the session value and forces a cart refresh
(`src/Frontend/PublicAjaxHandlers.php:88-98`).

Before any payment method is picked, the plugin **assumes COD** so the customer sees the worst-case price:

```php
// src/ShippingMethods/AbstractPigeonMethod.php:306-312
if ( $payment_method === '' ) {
    // No payment selected yet (first page load). Default to assuming COD ...
    return true;
}
```

### 2.3 Offices and lockers: stored in DB, refreshed on schedule

Stored in the custom table `{prefix}pigeon_locations` (`src/Installer.php:34-70`). Reads at checkout come
entirely from the DB, never from the API:

- Classic checkout inlines the **entire** office and APS datasets into the page via `wp_localize_script`
  (`src/Frontend/Assets.php:79-82`). No AJAX, no live carrier call.
- Blocks checkout fetches the dataset lazily on first use via `pigeon_express_wc_fetch_locations`
  (`src/Frontend/PublicAjaxHandlers.php:109-121`), which reads
  `BlocksCheckout::serialize_locations_by_type()` -> `LocationRepository::get_all_by_type()` (DB only).
  The comment at `src/Checkout/BlocksCheckout.php:170-175` explains this was a Plugin Check size fix.
- Admin office picker in Send From reads `LocationRepository::get_all_by_type()` (`src/Admin/SettingsPage.php:338`).
- Admin order-metabox location Select2 reads `LocationRepository::search_by_type()`
  (`src/Admin/AjaxHandlers.php:62`), a `LIKE` query against the local table.

### 2.4 Cities and streets: live, never stored

There is no cities table and no streets table. Both are looked up live against the carrier, only in admin
contexts:

```php
// src/Admin/SettingsPage.php:275
'desc' => __( 'Type to search the Pigeon Express city list. Results are looked up live against the carrier.', ... ),
```

- City search: `AjaxHandlers::search_cities()` -> `ApiClient::get_cities()` on every keystroke (`AjaxHandlers.php:436-477`).
- Street search: `AjaxHandlers::search_streets()` -> `ApiClient::get_streets()` (`AjaxHandlers.php:88-133`).
- The chosen city id/label and street id/label are then persisted as plain options
  (`pigeon_express_wc_sender_city_id` / `_city_label` / `_street_id` / `_street_label`) purely so the Select2
  can re-render the saved chip without another call (`src/Admin/SettingsPage.php:368-402`).
- Per-order street resolution result is cached on the shipping-line meta keyed by an md5 of
  `city_id|address string`, so re-rendering the metabox does not re-hit the API
  (`src/Admin/OrderMetaBox.php:438-449`, `persist_street_cache()` at `lines 510-523`).
  A transient API failure deliberately does not poison that cache (`OrderMetaBox.php:461-464`).

Customers never search cities or streets. At checkout, address delivery just passes whatever the customer typed
into `additional_info` with a placeholder city id (see §2.6).

### 2.5 Service definitions: DB (wp_options), schedule-refreshed

```php
// src/Cron/LocationSync.php:113-121
$active[ $code ] = [
    'label'        => (string) ( $service['name'] ?? ( $service['label'] ?? $code ) ),
    'group'        => (string) ( $service['group'] ?? '' ),
    'last_seen_at' => $now,
];
update_option( Settings::OPTION_ACTIVE_SERVICES, $active, false );
update_option( Settings::OPTION_SERVICES_LAST_SYNC, $now, false );
```

Option key: `pigeon_express_wc_active_services` (`src/Settings.php:13`). It stores **only** code, label and
`last_seen_at`. **No price, no fee, no input_type, no option_group is stored.** Note the sync reads
`$service['group']` while the spec's field is `option_group` (spec:3346), so `group` will always be empty
against the documented API.

### 2.6 Hardcoded values inventory

Everything the plugin hardcodes, with the exact location:

| Value | Location | Notes |
|---|---|---|
| `759` (probe city id) | `src/Api/ApiClient.php:52` | Used for the credentials health check |
| `759` (quote placeholder city id) | `src/Labels/BodyBuilder.php:697` | Only when no sender city is set; filterable via `pigeon_express_wc_default_quote_city_id` |
| `'wordpress_softart'` X-Platform | `src/Api/ApiClient.php:26` | Attribution header |
| `15` second timeout | `src/Api/ApiClient.php:22` | |
| `[ 'BG' ]` allowed countries | `src/ShippingMethods/AbstractPigeonMethod.php:199` | filter `pigeon_express_wc_available_countries` |
| `[ 'cod' ]` COD payment method ids | `AbstractPigeonMethod.php:314`, `BodyBuilder.php:714`, `OrderMetaBox.php:550` | filter `pigeon_express_wc_cod_payment_methods` |
| Default weight `1.0` kg | `src/Labels/BodyBuilder.php:514` | filter `pigeon_express_wc_default_weight` |
| Default box `40 x 40 x 40` cm | `src/Labels/BodyBuilder.php:519` and `:550` | filter `pigeon_express_wc_default_box_cm` |
| Minimum per-package weight `0.1` kg | `src/Labels/BodyBuilder.php:572-574` | clamps upward |
| Max weight `40.0` kg | `src/Labels/LabelFormValidator.php:157` | filter `pigeon_express_wc_max_weight_kg`. Note the spec says `maximum: 30` for `Package.weight` (spec:2674) |
| APS dimension caps `61 x 44 x 37` cm | `src/Labels/LabelFormValidator.php:39` | |
| Office/address dimension caps `250 x 180 x 150` cm | `src/Labels/LabelFormValidator.php:40` | Spec says `maximum: 200` per axis (spec:2679-2694) |
| "all three dims >= 60 cm is rejected" rule | `src/Labels/LabelFormValidator.php:41` | |
| Note max length `1000` chars | `src/Labels/BodyBuilder.php:133` | matches spec:2977 |
| Parcel description max `100` chars | `src/Admin/AjaxHandlers.php:308`, `SettingsPage.php:157` | spec allows 200 (spec:3001) |
| Picker result cap `30` | `assets/js/checkout-classic.js:28`, `assets/js/blocks-checkout.js:50` | UI only |
| Location sync page ceiling `200` | `src/Cron/LocationSync.php:172` | safety valve |
| Default service labels (9 entries) | `src/ShippingMethods/AbstractPigeonMethod.php:49-59` | pre-first-sync fallback only |
| `APPLICABLE_SERVICES` per method | `AddressDelivery.php:12-22`, `OfficeDelivery.php:12-22`, `ApsDelivery.php:21-26` | see §5 |
| Bulgarian street-type abbreviations | `src/Admin/AjaxHandlers.php:148-151`, `src/Util/AddressParser.php:28` | display + parsing only |
| BG phone regexes | `src/Util/Phone.php:75, 80` | |

**There is no hardcoded fee, percentage, surcharge or price anywhere in the plugin.** A grep across all PHP and
JS for `fee|percent|surcharge|markup|* 0.|/ 100|* 100` returns only the literal service code string
`cod_card_fee` and prose comments. The only numbers touching money are `total_price` read back from the API,
and `cod_amount` / `declared_value` which are the *cart total* passed **into** the API, not fees computed by
the plugin.

---

## 3. STORAGE MODEL

### 3.1 Custom DB table (exactly one)

`{$wpdb->prefix}pigeon_locations`, created via `dbDelta` in `src/Installer.php:40-70`:

| Column | Type | Purpose |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT PK | surrogate key |
| `external_id` | VARCHAR(100) NOT NULL | Pigeon office/locker id, sent as `delivery_office_id` |
| `type` | VARCHAR(16) NOT NULL | `'office'` or `'aps'` (API's `locker` normalised, `LocationRepository.php:26-31`) |
| `name` | VARCHAR(255) | |
| `address` | VARCHAR(500) | |
| `city` | VARCHAR(100) | display + search |
| `city_external_id` | VARCHAR(50) DEFAULT '' | Pigeon city id |
| `postcode` | VARCHAR(20) DEFAULT '' | drives classic-checkout postcode pre-filter |
| `lat` / `lng` | DECIMAL(10,7) NULL | stored, currently unused by any UI |
| `working_hours` | TEXT NULL | JSON blob, stored, currently unused by any UI |
| `raw_data` | LONGTEXT NULL | full JSON of the API row |
| `synced_at` | DATETIME NOT NULL | UTC via `current_time('mysql', true)` |

Indexes: `UNIQUE KEY external_id_type (external_id, type)`, `KEY type`, `KEY city`, `KEY city_external_id`.

Write strategy is delete-then-insert per type, so retired locations disappear rather than accumulate:

```php
// src/Repositories/LocationRepository.php:112-134
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE type = %s", $type ) );
foreach ( $rows as $row ) { $wpdb->insert( $table, $row, [...] ); }
```

Reads: `count_by_type`, `get_all_by_type`, `first_external_id_of_type`, `search_by_type` (LIKE across
name/address/city/external_id, capped at 200), `find_by_external_id`.

There is **no cities table and no streets table**.

### 3.2 WordPress options

Defined as constants in `src/Settings.php:9-34`, wiped by the allow-list in `uninstall.php:169-193`.

| Option key | Stores | Default |
|---|---|---|
| `pigeon_express_wc_api_key` | X-API-Key | `''` |
| `pigeon_express_wc_api_secret` | X-API-Secret | `''` |
| `pigeon_express_wc_sandbox` | `'yes'` = demo API, `'no'` = live | `'yes'` |
| `pigeon_express_wc_debug_log` | `'yes'`/`'no'` | `'no'` |
| `pigeon_express_wc_active_services` | array `code => { label, group, last_seen_at }` | `[]` |
| `pigeon_express_wc_services_last_sync` | UTC mysql datetime | `''` |
| `pigeon_express_wc_credentials_validated` | `'yes'`/`'no'`, gates the whole Send From + Sync UI | `'no'` |
| `pigeon_express_wc_credentials_validated_at` | UTC mysql datetime | `''` |
| `pigeon_express_wc_locations_last_sync` | UTC mysql datetime of last `run_full()` | `''` |
| `pigeon_express_wc_pickup_type` | `'address'` or `'office'` | `'address'` |
| `pigeon_express_wc_sender_name` | label sender name | `''` |
| `pigeon_express_wc_sender_phone` | sender phone | `''` |
| `pigeon_express_wc_sender_email` | sender email | `get_option('admin_email')` |
| `pigeon_express_wc_sender_city_id` | Pigeon city id for pickup | `''` |
| `pigeon_express_wc_sender_city_label` | cached human label for the Select2 chip | `''` |
| `pigeon_express_wc_sender_street_id` | Pigeon street id for pickup | `''` |
| `pigeon_express_wc_sender_street_label` | cached human label | `''` |
| `pigeon_express_wc_sender_address` | house number / floor / apartment free text | `''` |
| `pigeon_express_wc_sender_office_id` | pickup office id when pickup_type=office | `''` |
| `pigeon_express_wc_status_after_label` | order status slug to auto-transition to | `''` (don't change) |
| `pigeon_express_wc_parcel_description` | global "Contents" text for labels, max 100 | `''` |
| `pigeon_express_wc_fragile_default` | `'yes'` pre-checks `declared_value` per order | `'no'` |
| `pigeon_express_wc_default_who_pays` | `'sender'` or `'receiver'` | `'receiver'` |
| `pigeon_express_wc_uninstall_data` | `'yes'` opts into the destructive uninstall | `'no'` |
| `pigeon_express_wc_db_version` | schema version guard (`src/Installer.php:11`) | set on activate |

Note `pigeon_express_wc_sender_street_label` is **missing** from the uninstall allow-list at
`uninstall.php:169-193` (a minor leak; `_sender_street_id` is also missing). Everything else is covered.

Per-method WooCommerce settings live under WC's own convention `woocommerce_{method_id}_settings` and
`woocommerce_{method_id}_{instance_id}_settings`, LIKE-cleaned at `uninstall.php:203-210`.

### 3.3 Transients

- `pigeon_express_wc_credentials_notice` (global, 5 min) for the post-save admin notice
  (`src/Admin/CredentialsValidator.php:25, 222-231`).
- `pigeon_express_wc_bulk_notice_{user_id}` (per user, 5 min) for bulk-action results
  (`src/Admin/BulkActions.php:28, 210-223`).

### 3.4 WC session

`pigeon_express_wc_selected_locations` (`src/Checkout/CheckoutSession.php:17`), a map of
`method_id => { external_id, name, address, city, city_external_id }`. Keyed per method so switching between
Office and APS does not lose either pick.

### 3.5 Order meta (HPOS-safe, all `_pe_wc_` prefixed)

`src/Repositories/OrderShippingRepository.php:23-52`:

| Meta key | Content |
|---|---|
| `_pe_wc_shipping_type` | `address` / `office` / `aps` |
| `_pe_wc_location_id` | chosen office/locker external id |
| `_pe_wc_location_name` / `_address` / `_city` | denormalised display fields for emails and admin |
| `_pe_wc_shipping_price` / `_pe_wc_shipping_currency` | **declared but never written**, `set_quoted_price()` has no callers |
| `_pe_wc_shipment_reference` | carrier reference number |
| `_pe_wc_shipment_response` | JSON of the create response, with `label_pdf` stripped out |
| `_pe_wc_label_pdf_b64` | legacy in-meta base64 PDF, read-only fallback |
| `_pe_wc_label_pdf_file` | filename of the PDF on disk |
| `_pe_wc_label_created_at` | UTC mysql datetime |
| `_pe_wc_form_{field}` | persisted metabox form state, 13 scalar fields (`FORM_FIELDS`, lines 38-52) |
| `_pe_wc_form_packages` / `_pe_wc_form_services` | JSON blobs (`FORM_STRUCTURED_FIELDS`, lines 59-62) |

Label PDFs are written to `{uploads}/pigeon-express-for-woocommerce/labels/{reference}.pdf` with an atomic
`.tmp` + `rename()` (`OrderShippingRepository.php:120-154`), protected by a generated `.htaccess` "Deny from all"
plus an empty `index.html` (`lines 174-182`). The stated reason: HPOS `wp_wc_orders_meta.meta_value` is `TEXT`
(~64 KB) and multi-page label PDFs were being silently truncated (`lines 16-20`).

### 3.6 Order shipping-line item meta

Written by `AbstractPigeonMethod::calculate_shipping()` at `lines 178-187` inside `meta_data`:

`delivery_type`, `free_shipping`, `fixed_price`, `validated`, `errors`, `pe_wc_request_body`,
`pe_wc_api_response`, plus one `service_{code}` key per enabled service (read back at
`src/Labels/LabelService.php:116` and `src/Admin/OrderMetaBox.php:581`).

The street auto-resolve cache also lives here: `_pe_wc_auto_street_source_hash`, `_pe_wc_auto_street_id`,
`_pe_wc_auto_street_label`, `_pe_wc_auto_street_residual` (`src/Admin/OrderMetaBox.php:518-522`).

### 3.7 uninstall.php

Default is **do nothing** unless `pigeon_express_wc_uninstall_data === 'yes'` (`uninstall.php:52-54`). When
opted in, in order: delete the uploads label directory recursively, `wp_unschedule_hook` the cron, delete
transients, delete `_pe_wc_%` meta from both `wc_orders_meta` and `postmeta`, delete `_pe_wc_%` and `pe_wc_%`
item meta from both itemmeta tables, `DROP TABLE {prefix}pigeon_locations`, delete the option allow-list, and
LIKE-delete `woocommerce_pigeon_express_*` options. Deliberately self-contained with no autoloader dependency
(`uninstall.php:26-27`).

---

## 4. SYNC AND CRON

Single cron job. `src/Cron/LocationSync.php`.

- Hook: `pigeon_express_wc_daily_location_sync` (`LocationSync.php:21`).
- Schedule: WP-Cron `daily`, first run one hour after scheduling
  (`wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK )`, `LocationSync.php:35`).
- Scheduled on activation (`src/Installer.php:16`) and idempotently re-scheduled on every boot so in-place
  upgrades that skip the activation hook self-heal (`src/Plugin.php:38-41`).
- Unscheduled on deactivation (`src/Installer.php:20`) and belt-and-braces in `uninstall.php:78`.

### 4.1 What one run does

`run_full()` (`LocationSync.php:63-78`) performs three stages, always all three, in order:

1. `sync_services()`: `GET /services`, rebuilds the `pigeon_express_wc_active_services` option, sets
   `pigeon_express_wc_services_last_sync`.
2. `sync_locations( 'office', 'office' )`: pages `GET /offices?type=office`, `replace_all_of_type('office')`.
3. `sync_locations( 'aps', 'locker' )`: pages `GET /offices?type=locker`, `replace_all_of_type('aps')`.

Then unconditionally stamps `pigeon_express_wc_locations_last_sync = current_time('mysql', true)`
(`LocationSync.php:70`), **even if every stage errored**. Returns
`{ services: int, offices: int, aps: int, errors: string[] }`.

### 4.2 Failure behaviour

- Services failure: appends a translated error string and returns 0; the previously stored `active_services`
  option is **left untouched** (`LocationSync.php:83-91`). Good: a transient failure does not empty the catalogue.
- Locations failure on page N: appends an error and `break`s out of the paging loop
  (`LocationSync.php:139-148`). If nothing at all was collected, `replace_all_of_type` is never called, so the
  existing rows survive (`lines 174-176`). If a partial set was collected, **the partial set replaces
  everything**, which is a genuine data-loss hazard worth improving in the Magento port.
- Paging guard: `while ( $current < $last && $page <= 200 )` (`LocationSync.php:172`).
- No retry, no backoff, no failure counter, no admin notice raised from the cron path.

### 4.3 Staleness tracking

Two timestamps, both UTC mysql strings: `pigeon_express_wc_locations_last_sync` and
`pigeon_express_wc_services_last_sync`. Only the first is surfaced in the UI. Per-row `synced_at` also lives on
every locations row but is never read.

The Location sync settings panel renders (`src/Admin/SettingsPage.php:407-466`):

- `Currently stored: %d offices, %d parcel lockers, %d active services.`
- `Last sync: %s UTC.` or `Last sync: never.`
- `Next automatic sync: %s.` from `wp_next_scheduled( LocationSync::CRON_HOOK )`, or `not scheduled`.

The whole panel is hidden until credentials validate (`SettingsPage.php:408-410`).

### 4.4 Manual triggers

Two:

1. **Force sync now** button (`src/Admin/SettingsPage.php:445-448`, handled by
   `AjaxHandlers::force_sync()` at `AjaxHandlers.php:404-434`). Requires `manage_woocommerce`, a nonce, and
   validated credentials. Runs `run_full()` synchronously and returns the counts plus per-stage errors.
   JS wiring at `assets/js/admin.js:172-173`.
2. **Initial sync after first credential validation** (`src/Admin/CredentialsValidator.php:181-192`). Runs
   `run_full()` synchronously, but **only when the locations table is empty**:

```php
$has_data = LocationRepository::count_by_type( TYPE_OFFICE ) > 0
         || LocationRepository::count_by_type( TYPE_APS ) > 0;
if ( $has_data ) { return null; }
```

---

## 5. ADDITIONAL SERVICES: deep dive

### 5.1 Discovery

Only one path: the daily/manual sync calls `GET /services` and normalises each row into
`{ label, group, last_seen_at }` keyed by `code` (`src/Cron/LocationSync.php:102-121`). Code resolution is
`$service['code'] ?? $service['id']`; label resolution is `$service['name'] ?? $service['label'] ?? $code`.

Per the spec the correct route is `GET /v1/additional-services` and it also returns `description`,
`input_type` and `option_group` (spec:3308-3356). **The plugin discards `description`, never reads
`input_type`, and reads a non-existent `group` key instead of `option_group`.** Consequence: the plugin has no
runtime knowledge of which services are checkbox vs numeric vs mutually exclusive. It hardcodes that knowledge
instead (see §5.4).

### 5.2 Which services are "active"

`Settings::get_active_services()` (`src/Settings.php:64-68`) returns the option blob. Membership is the only
liveness test:

```php
// src/Settings.php:70-72
public static function is_service_active( string $code ): bool {
    return array_key_exists( $code, self::get_active_services() );
}
```

`is_service_active()` itself has no callers; the same `array_key_exists` check is inlined in two places
(`AbstractPigeonMethod.php:342`, `BodyBuilder.php:623`).

### 5.3 Which services apply per delivery method: hardcoded whitelists

Each shipping-method subclass declares a `protected const APPLICABLE_SERVICES` array, exposed via the static
`applicable_services()` accessor on the base class (`src/ShippingMethods/AbstractPigeonMethod.php:410-412`).

`AddressDelivery` (`src/ShippingMethods/AddressDelivery.php:12-22`) and `OfficeDelivery`
(`src/ShippingMethods/OfficeDelivery.php:12-22`) share an identical list of 9:

```
declared_value
return_receipt
paper_return_receipt
service_return_documents
ID_verification_and_document_signature
cod_card_fee
shipment_test_before_payment
pay_with_pos
sms_notification
```

`ApsDelivery` (`src/ShippingMethods/ApsDelivery.php:21-26`) carries only 4:

```
declared_value
cod_card_fee
pay_with_pos
sms_notification
```

The rationale is documented at `ApsDelivery.php:7-15`: a locker has no human to verify ID, witness an
unboxing, or accept a paper return receipt, so the carrier rejects those service codes on an APS leg.

`LabelService::applicable_services_for_method()` (`src/Labels/LabelService.php:336-347`) is the single dispatch
point used by the order-side flow.

### 5.4 Hardcoded transport semantics per code

`BodyBuilder::services()` (`src/Labels/BodyBuilder.php:606-651`) is where a code becomes a payload field, and
the mapping is entirely hardcoded:

```php
$payload = [
    'service_type'  => 'standard',
    'service_codes' => [],
    'who_pays'      => $is_cod ? 'receiver' : 'sender',
];
$active = Settings::get_active_services();

foreach ( $applicable_codes as $code ) {
    if ( ! $is_enabled( $code ) ) { continue; }
    if ( ! empty( $active ) && ! array_key_exists( $code, $active ) ) {
        // Configured but the API no longer advertises it; skip silently.
        continue;
    }
    if ( $code === 'pay_with_pos' )     { $payload['pay_with_pos'] = true;     continue; }
    if ( $code === 'sms_notification' ) { $payload['sms_notification'] = true; continue; }
    if ( $code === 'declared_value' )   { $payload['service_codes']['declared_value'] = $cart_total; continue; }
    $payload['service_codes'][ $code ] = true;
}

if ( $is_cod && $cart_total > 0 ) {
    $payload['service_codes']['cod_amount'] = $cart_total;
}
```

Four hardcoded behaviours worth carrying into the Magento module:

1. `pay_with_pos` and `sms_notification` are lifted to **top-level body fields**, not into `service_codes`.
   The spec defines neither at top level in `CreateShipmentRequest` (spec:2881-3100), and neither code appears
   in the documented `/additional-services` example (spec:1840-1900) where the SMS code is
   **`sms_notification_receiver`**. This is a likely divergence to verify against the live API before porting.
2. `declared_value` is a **numeric** service, and the plugin fills it with the cart total (checkout path) or
   the COD amount (order path, see §5.7). It is never a merchant-entered figure.
3. `service_type` is always the literal `'standard'`. Express is never offered.
4. `cod_amount` is injected automatically whenever the payment method is COD, keyed inside `service_codes`.

Also note: `service_type: 'standard'` is one of the four required fields on `CalculateShipmentRequest`
(spec:2830-2836), and `who_pays` is additionally required on `CreateShipmentRequest` (spec:3092-3101).

### 5.5 Labels

`AbstractPigeonMethod::service_label()` (`src/ShippingMethods/AbstractPigeonMethod.php:372-388`), the single
label resolver shared by the settings UI and the order metabox, uses a 4-step precedence:

1. Hard override: `declared_value` always renders as **"Fragile (declared value)"**, described as the
   merchant-facing name (`line 373-375`).
2. The synced label from `active_services[code]['label']`.
3. The hardcoded `DEFAULT_SERVICE_LABELS` map (`lines 49-59`, 9 entries).
4. `ucfirst( strtolower( str_replace(['_','-'], ' ', $code) ) )`.

### 5.6 Admin presentation

**Per shipping-method settings (WC zone modal).** `init_form_fields()` emits one checkbox per code named
`service_{code}` (`AbstractPigeonMethod.php:131-142`). Which checkboxes appear comes from
`resolve_service_choices()` (`lines 328-356`): the method's whitelist intersected with the synced active set;
if no sync has run yet, the full whitelist is shown with fallback labels. The section header text spells out
the runtime behaviour: *"Inactive services (no longer advertised by the API) are skipped automatically at
shipment time"* (`line 127`).

**Per-order metabox.** Renders the **union** of all three methods' whitelists so the JS delivery-type switcher
can show/hide rows without an AJAX round trip (`src/Admin/OrderMetaBox.php:113-143`). Each row carries
`data-applicable-methods` and is disabled + hidden when it does not apply to the currently selected method
(`templates/admin/order-label-box.php:406-426`). Client-side filtering at `assets/js/admin.js:967-975`.

Pre-check precedence, `compute_effective_services()` (`OrderMetaBox.php:566-597`):
form override (saved `_pe_wc_form_services`) -> shipping-line `service_{code}` meta (`'yes'`/`'no'`) ->
`declared_value` gets the global "Always mark orders as fragile" default, everything else defaults off.

The submit protocol handles unchecking properly: a hidden `services_applicable[]` field lists every code that
was on screen, so an empty `services[]` array means "uncheck all" rather than "no opinion"
(`src/Admin/AjaxHandlers.php:252-282`, comment at `lines 246-251`).

### 5.7 Checkout presentation

**None.** Additional services are never shown to the customer and are never selectable at checkout. They are
purely a merchant-side per-method or per-order decision. The only customer-visible consequence is that the
enabled services change the quoted `total_price`.

### 5.8 HOW ANY SERVICE FEE IS DETERMINED: the answer

**Entirely server-side, by the Pigeon Express API. The plugin never computes, stores or displays a
per-service fee.**

Evidence:

- The only monetary value taken from a response is `$response['data']['total_price']`
  (`src/ShippingMethods/AbstractPigeonMethod.php:249`). The spec's sibling fields `shipping_price` and
  `total_service_fees` (spec:2841-2851) are never read. The spec's `ServiceFee { name, amount }` schema
  (spec:2870-2879) is never referenced anywhere in the plugin.
- The `active_services` option stores only `label`, `group` and `last_seen_at`
  (`src/Cron/LocationSync.php:113-117`). No price field is stored, because `/services` (and
  `/additional-services` in the spec) does not return one.
- A grep for `fee|percent|surcharge|markup|* 0.|/ 100|* 100` across all PHP and JS returns zero arithmetic
  hits; only the literal code string `cod_card_fee` and explanatory comments.
- `cod_card_fee` is transported as a plain boolean `service_codes['cod_card_fee'] = true`
  (`BodyBuilder.php:643`, the generic branch). The plugin has no idea what it costs.

The practical mechanism: enabling a service changes the request body, the carrier reprices, and the new
`total_price` becomes the WooCommerce shipping rate. The COD card fee "disappearing" when the customer
switches away from COD (`src/Checkout/CheckoutIntegration.php:52-55`) is purely a consequence of re-quoting
with a different body, not of any local subtraction.

**Implication for the Magento port: do not attempt to model per-service pricing locally. Model the request
body faithfully and trust `total_price`.** If a fee breakdown is ever needed in the UI, `shipping_price` +
`total_service_fees` are already returned and just need to be captured.

---

## 6. COD END TO END

### 6.1 Detecting COD

Three independent copies of the same logic, all filterable through `pigeon_express_wc_cod_payment_methods`
with default `[ 'cod' ]`:

- Checkout / cart package: `AbstractPigeonMethod::is_cod_for_package()` (`lines 297-317`). Reads
  `$package['pe_wc_chosen_payment_method']` (injected by
  `CheckoutIntegration::attach_payment_method_to_packages()`), falling back to
  `WC()->session->get('chosen_payment_method')`. **Empty means assume COD** (`lines 306-312`).
- Order-side body: `BodyBuilder::is_cod_order()` (`lines 713-717`), reads `$order->get_payment_method()`.
- Metabox render: `OrderMetaBox::is_cod_order()` (`lines 549-553`), same check duplicated.

### 6.2 Checkout

- Rate cache is keyed on payment method so a COD flip re-quotes (see §2.2).
- The COD amount sent on the **quote** is the cart contents cost:
  `$cart_total = $package['contents_cost']` (`AbstractPigeonMethod.php:155`, passed into
  `BodyBuilder::for_package()` at `line 61`, then into `services()` at `line 63`), producing
  `service_codes.cod_amount = <contents cost>` when COD (`BodyBuilder.php:646-648`).
- No COD fee line, no COD surcharge and no COD-specific messaging is rendered to the customer anywhere.
  The customer only sees a shipping price that differs between COD and non-COD.

### 6.3 Order meta

There is **no dedicated COD order meta key.** The COD amount is derived on demand:

```php
// src/Labels/BodyBuilder.php:345-351
private static function cod_amount_for( WC_Order $order, array $overrides ): float {
    if ( isset( $overrides['cod_amount'] ) && $overrides['cod_amount'] !== '' ) {
        return (float) $overrides['cod_amount'];
    }
    return (float) $order->get_total();
}
```

If the merchant edits the value in the metabox, it is persisted as the form-state key
`_pe_wc_form_cod_amount` (`OrderShippingRepository::FORM_FIELDS`, `line 45`), which exists so a cancel +
recreate re-opens the form with the corrected figure.

### 6.4 Admin display

The COD field only renders when the order's payment method is COD
(`templates/admin/order-label-box.php:388-401`):

```php
<?php if ( $is_cod ) : ?>
  <label for="pe-wc-cod_amount">COD amount</label>
  <input type="number" name="cod_amount" value="<?= $prefill['cod_amount'] ?>" min="0" step="0.01" />
  <span class="description">Amount the courier collects on delivery. Defaults to the order total.</span>
```

Default prefill is `(float) $order->get_total()` (`src/Admin/OrderMetaBox.php:155`), i.e. the **full order
total including shipping**, not the goods subtotal. Validation is minimal: numeric and `>= 0`
(`src/Labels/LabelFormValidator.php:94-103`).

### 6.5 What is actually sent

On order/label creation, `cod_amount_for()` is passed as the `$cart_total` argument to `services()`
(`src/Labels/BodyBuilder.php:109`), so:

- `service_codes.cod_amount = <cod amount>` when COD and > 0 (`BodyBuilder.php:646-648`).
- **If `declared_value` is also enabled, it is set to the same COD figure** (`BodyBuilder.php:638-641`),
  because `services()` takes a single `$cart_total` parameter reused for both. On the checkout quote path
  that value is the cart contents cost; on the order path it is the COD amount / order total.
- `cod_card_fee`, when enabled, is a bare `true`.
- COD is **not** sent at all for non-COD orders: `cod_amount` is omitted entirely.
- `who_pays` is decided independently of COD (see below).

### 6.6 who_pays interaction

`resolve_who_pays()` (`src/Labels/BodyBuilder.php:669-679`), precedence:

1. Per-order metabox override (`sender` / `receiver`).
2. `sender` whenever the checkout rate was a free-shipping rate (read from the shipping-line meta
   `free_shipping`, `src/Labels/LabelService.php:99`). Rationale documented at `BodyBuilder.php:656-661`:
   the store already agreed to absorb shipping.
3. The global `pigeon_express_wc_default_who_pays` setting, default `receiver`.

The `$is_cod` argument is still accepted but explicitly no longer consulted (`BodyBuilder.php:662-665`); the
global setting subsumed the old COD heuristic. On the **quote** path, however, the COD heuristic is still
live: `'who_pays' => $is_cod ? 'receiver' : 'sender'` (`BodyBuilder.php:610`). So checkout quotes and the
final label can legitimately disagree on `who_pays` when the merchant's global default is `sender`.

Every resolution is logged with its inputs (`BodyBuilder.php:116-125`), a `CONTEXT` line carrying order id,
payment method, is_cod, free_shipping, method id, override and resolved value.

### 6.7 Not implemented

The spec's `GET /v1/payments/completed` COD-payout reconciliation endpoint (spec:1908) is not used. There is
no COD reconciliation or payout report in the plugin.

---

## 7. FEATURE INVENTORY

### Checkout (customer-facing)

- Three separate WooCommerce shipping methods, each independently enableable per shipping zone:
  `pigeon_express_address`, `pigeon_express_office`, `pigeon_express_aps`
  (`src/Plugin.php:82-88`).
- Live price quote per rate calculation via `POST /shipments/calculate`; the method still appears (at cost 0)
  when the quote fails, so checkout is never blocked (`AbstractPigeonMethod.php:147-193`).
- Per-method **Fixed price** override that bypasses the API entirely when > 0.
- Per-method **Free shipping over** cart-total threshold.
- Free-shipping coupon support: any coupon with `get_free_shipping()` zeroes the rate
  (`AbstractPigeonMethod.php:390-402`).
- Country gate: BG only by default, filterable (`AbstractPigeonMethod.php:195-204`).
- Searchable office picker (classic checkout) rendered inline under the chosen rate
  (`src/Checkout/ClassicCheckout.php:18-47`, `templates/checkout/location-picker.php`).
- Searchable parcel-locker (APS) picker, same mechanism.
- Picker pre-filters to locations matching the customer's billing/shipping postcode, falling back to the full
  pool when nothing matches (`assets/js/checkout-classic.js:13-15, 106-113`).
- Picker caps the visible list at 30 results with a "keep typing to narrow" hint.
- Full WooCommerce **Blocks Checkout** support with its own React integration and lazy dataset fetch
  (`src/Checkout/BlocksCheckout.php`, `assets/js/blocks-checkout.js`).
- Only one picker renders at a time, for the rate the customer actually chose
  (`ClassicCheckout::rate_is_chosen()`, `lines 54-68`).
- Switching shipping method clears the other methods' stored selections server-side
  (`assets/js/checkout-classic.js:352-377`).
- Shipping cost auto-refreshes when the customer changes payment method (COD-aware pricing), on both classic
  and Blocks checkout.
- Hard validation: submitting with an office/APS method but no location selected is blocked. Classic path via
  `woocommerce_after_checkout_validation` (`src/Checkout/CheckoutIntegration.php:160-177`); Blocks path via a
  `RouteException` on the Store API (`src/Checkout/BlocksCheckout.php:262-293`).
- Chosen location persisted to order meta on both order-creation pipelines
  (`CheckoutIntegration::persist_to_order`, hooked to `woocommerce_checkout_create_order` and
  `woocommerce_store_api_checkout_update_order_from_request`, `lines 31-48`).

### Admin: settings (WooCommerce > Settings > Shipping > Pigeon Express)

- API key + API secret (rendered as password fields, `src/Admin/SettingsPage.php:101-117`).
- Environment radio, Sandbox vs Live, with explicit warning copy (`lines 119-130`).
- Colour-coded connection status banner: not connected / connected sandbox / connected live / auth failed
  (`status_banner()`, `lines 468-498`).
- Automatic credential validation on save, with a `GET /cities/759` probe and a detailed error notice carrying
  environment, HTTP status and the carrier's own message (`src/Admin/CredentialsValidator.php:101-135`).
- **Progressive disclosure**: the Send From and Location sync groups only render once credentials validate
  (`SettingsPage.php:206-208, 408-410`).
- Debug log toggle (writes to the WooCommerce log viewer under source `pigeon-express`).
- Order status after label creation (dropdown of WC statuses minus refunded/failed/cancelled,
  `status_after_label_options()`, `lines 513-527`).
- Global parcel description (label "Contents" text, max 100 chars).
- "Always mark orders as fragile" toggle (pre-checks `declared_value` per order).
- Default "Who pays the courier" (receiver / sender).
- On-uninstall radio: keep all data (default) vs delete everything permanently.
- Send From: pickup type (address / office), sender name, phone, email, pickup office (from synced list),
  pickup city (live AJAX Select2), pickup street (live AJAX Select2, filtered by city), number/floor/apartment.
- WC settings-save workaround so AJAX-populated Select2 values are not wiped by WC's option validation
  (`SettingsPage.php:39-66`).
- Location sync panel: stored counts, last sync UTC timestamp, next scheduled run, and a **Force sync now**
  button with inline status.

### Admin: per-method settings (inside each shipping zone)

- Enable/disable, method title, fixed price, free shipping over.
- One checkbox per applicable additional service, dynamically built from the synced catalogue with hardcoded
  fallback labels (`AbstractPigeonMethod::init_form_fields()`, `lines 93-145`).

### Admin: order edit screen

- "Pigeon Express shipment" metabox, registered on both the classic `shop_order` screen and the HPOS orders
  screen (`OrderMetaBox::screen_ids()`, `lines 617-637`), rendered in the main column at high priority.
- Only appears on orders that have a Pigeon Express shipping line (`OrderMetaBox.php:46-48`).
- Two states: "No shipment yet" (full form) or "Shipment created" (reference, inline PDF preview iframe,
  Open label PDF, Cancel shipment).
- Delivery-type switcher (office / address / APS) that re-shapes the form client-side without a reload
  (`assets/js/admin.js:883-975`), overriding what the customer chose at checkout.
- Editable receiver name / phone / email, prefilled from shipping then billing.
- Multi-package table: add/remove rows, per-row weight, length, width, height and description (max 100 chars),
  with a `<template>` row cloner.
- Smart package prefill: sums line-item weights; if the order is a single line item with product dimensions
  set, uses those converted to cm, otherwise 40 cm defaults for office/address and blank for APS
  (`compute_default_packages()` + `infer_product_dimensions()`, `OrderMetaBox.php:279-348`).
- Address-delivery block with city Select2 (live API search), street Select2 (live API search, city-scoped)
  and a house-number field.
- **Automatic city and street resolution** from the order's billing/shipping address, with the street parsed
  out of free text by `AddressParser` and the remainder pushed into the house-number field
  (`OrderMetaBox.php:372-496`, `src/Util/AddressParser.php`).
- Office / locker Select2 backed by the local synced table.
- COD amount field, shown only for COD orders, defaulting to the order total.
- Additional-services checkbox list, filtered live to the selected delivery type.
- Who-pays-the-courier dropdown with the computed default explained inline.
- Note for the courier (max 1000 chars).
- Full-form validation aggregating every field error into one bullet list
  (`src/Labels/LabelFormValidator.php`, `format_message()` joins with a bullet separator at `line 298`).
- Bulgarian phone validation and normalisation to `359XXXXXXXXX` before sending
  (`src/Util/Phone.php`).
- Create shipment, entirely via AJAX with in-place metabox re-render (`AjaxHandlers::respond_with_metabox()`,
  `lines 370-402`).
- Cancel shipment, which voids at the carrier, deletes reference / response / created-at meta and removes the
  local PDF (`src/Labels/LabelService.php:222-251`).
- Form state persisted across a cancel + recreate cycle (`OrderShippingRepository::save_form_state()`).
- Guard against duplicates: creating a second shipment on an order that already has a reference is refused
  (`LabelService.php:52-57`).
- HPOS double-save workaround: the order is re-fetched before writing shipment meta (`LabelService.php:142-156`).
- Explicit "labels are immutable, cancel and recreate to change anything" notice
  (`templates/admin/order-label-box.php:100-103`).

### Admin: orders list

- "Pigeon Express" column, slotted after Shipping address, registered for both classic and HPOS screens
  (`src/Admin/OrderListColumn.php:33-49`).
- Cell shows either a pending badge with the delivery type, or the reference number plus a printer icon
  linking to the label (`render_cell()`, `lines 100-139`).
- Filter dropdown with 7 options: all, Pigeon any, label pending, label created, address, office, parcel
  locker (`filter_options()`, `lines 212-222`), implemented as meta_query for both storage modes.
- Bulk action **Create Pigeon Express shipments**, skipping orders with no Pigeon line or an existing
  shipment, and reporting created/skipped/failed with per-order error messages
  (`src/Admin/BulkActions.php:73-137`).
- Bulk action **Download Pigeon Express labels (ZIP)**, streaming a `pigeon-labels-YYYY-MM-DD.zip`
  (`BulkActions.php:142-208`).

### Labels

- Label PDF arrives base64 inside the create-shipment response (`data.label_pdf`) and is decoded and written
  to disk atomically; only the filename lands in order meta.
- Storage directory `{uploads}/pigeon-express-for-woocommerce/labels/`, protected with `.htaccess`
  "Deny from all" and an empty `index.html`.
- Streamed to the browser only through the nonce + `manage_woocommerce` guarded
  `pigeon_express_wc_download_label` AJAX action (`AjaxHandlers::guard_label_request()`, `lines 346-362`).
- Legacy fallback to the old in-meta base64 storage for orders created before disk storage
  (`OrderShippingRepository::get_label_pdf_binary()`, `lines 250-266`).
- Order note added if the API returned a PDF but the disk write failed, telling the merchant to cancel and
  recreate (`LabelService.php:164-181`).

### Emails and customer-facing order views

- A "Pigeon Express delivery destination" table (delivery type, location name, address + city) is injected
  into every transactional email via `woocommerce_email_after_order_table`, and into My Account > Orders >
  View order via `woocommerce_order_details_after_order_table` (`src/Emails/OrderConfirmation.php:25-31`).
- Rendered for office and APS orders only; address delivery has nothing extra to show
  (`collect_data()`, `lines 75-112`).
- Both HTML and plain-text variants (`format_html()`, `format_plain_text()`).
- Prefers the actual shipping-line label the customer saw at checkout over a generic string, so per-zone
  custom titles are respected (`shipping_line_label()`, `lines 114-128`).

### Order notes

- One private note per Pigeon shipping line at checkout time, recording the method name, delivery type and a
  price summary of "free shipping applied" / "fixed price N" / "live quote N" / "price not validated by the
  carrier" (`src/Admin/OrderNotes.php:50-83`).
- Note on label creation: reference number, package count and total kg, destination summary, and who pays the
  courier (`LabelService::format_shipment_created_note()`, `lines 356-399`).
- Note on cancellation with the voided reference (`LabelService.php:244-248`).
- Note on auto status transition (`LabelService.php:207-214`).

### Cron

- One daily WP-Cron job syncing offices, parcel lockers and the active services catalogue.
- Manual "Force sync now" button.
- Automatic initial sync on first successful credential validation, only when the table is empty.

### Tracking

- **Not implemented as a user-facing feature.** `ApiClient::get_shipment_tracking()` exists but has no callers.
  No tracking column, no tracking email, no customer tracking link.

### Extensibility hooks (filters)

`pigeon_express_wc_shipping_rate`, `pigeon_express_wc_available_countries`,
`pigeon_express_wc_method_is_available`, `pigeon_express_wc_calculate_body`,
`pigeon_express_wc_cod_payment_methods`, `pigeon_express_wc_shipment_body`,
`pigeon_express_wc_default_weight`, `pigeon_express_wc_default_box_cm`,
`pigeon_express_wc_max_weight_kg`, `pigeon_express_wc_default_quote_city_id`,
`pigeon_express_wc_field_selectors`, `pigeon_express_wc_address_format`.

Template override: `yourtheme/pigeon-express-wc/checkout/location-picker.php`
(`src/Checkout/ClassicCheckout.php:71`).

---

## 8. Divergences from the OpenAPI spec worth resolving before porting

1. **`/services` vs `/additional-services`.** The plugin calls `GET /services` (`ApiClient.php:16`); the spec
   only documents `GET /v1/additional-services` (spec:1800).
2. **`sms_notification` vs `sms_notification_receiver`.** The plugin uses the former as a top-level boolean
   (`BodyBuilder.php:633-636`); the spec's example catalogue lists `sms_notification_receiver` as a
   `service_codes` checkbox (spec:1842).
3. **`pay_with_pos`** is sent as a top-level boolean (`BodyBuilder.php:628-631`) but appears neither in
   `CreateShipmentRequest` nor in the documented services catalogue.
4. **`group` vs `option_group`.** The sync reads `$service['group']` (`LocationSync.php:115`); the spec's field
   is `option_group` (spec:3346). Mutual-exclusion groups are therefore invisible to the plugin. Note the spec
   explicitly flags `shipment_review` and `shipment_test_before_payment` as mutually exclusive
   (spec:1813-1816), and the plugin whitelists `shipment_test_before_payment` without any group awareness.
5. **`input_type` is ignored.** The plugin hardcodes which codes are numeric (`declared_value`, `cod_amount`)
   instead of reading `input_type: "text"` from the catalogue (spec:3327-3341).
6. **Weight and dimension caps differ.** Plugin: 40 kg, 250/180/150 cm for office+address, 61/44/37 cm for APS
   (`LabelFormValidator.php:39-40, 157`). Spec: `Package.weight` max 30, each dimension max 200
   (spec:2668-2697).
7. **`street_number` vs `additional_info`.** The plugin always sends the house number inside `additional_info`
   (`BodyBuilder.php:239-246, 272-282`); the spec's `Address` schema has a dedicated `street_number` field plus
   `building_number`, `entrance`, `floor`, `apartment` (spec:2699-2765).
8. **Unused spec capabilities** that may be desirable in the Magento module: `external_reference` (put the
   Magento increment id on the shipment, spec:2983), `label_format` (`default|pdf150|zpl300`, spec:2988),
   `return_at_my_expense` (spec:2972), receiver company block for B2B invoicing (spec:2894-2926),
   `GET /shipments/{ref}/label` for label recovery (spec:1578), `GET /payments/completed` for COD payout
   reconciliation (spec:1908), and the courier-request endpoints (spec:2011).
