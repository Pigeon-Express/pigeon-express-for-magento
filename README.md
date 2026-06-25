# PigeonExpress Shipping — Magento 2 Module

Magento 2 shipping carrier integration for [Pigeon Express](https://pigeonexpress.com) courier service. Supports delivery to address, office, and APS parcel lockers with fixed or dynamic (API-based) pricing.

---

## Requirements

- Magento 2.4.x
- PHP 8.1+
- [pigeonexpress-php-sdk](https://github.com/pigeonexpress/pigeonexpress-php-sdk)

---

## Installation

### Via Composer (recommended)

**1. Add the repositories**

While the repositories are private, register both as VCS repositories (skip this
step once the packages are published to Packagist):

```bash
composer config repositories.pigeonexpress-module vcs https://github.com/Pigeon-Express/pigeon-express-for-magento.git
composer config repositories.pigeonexpress-sdk vcs https://github.com/Pigeon-Express/pigeon-express-php-sdk.git
```

**2. Require the module**

```bash
composer require pigeonexpress/module-shipping:^1.0
```

Composer will automatically install the PHP SDK (`pigeonexpress/php-sdk`) as a dependency.

**3. Enable the module**

```bash
php bin/magento module:enable PigeonExpress_Shipping
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

---

### Manual installation

### 1. Copy the module

Place the module into your Magento installation:

```
app/code/PigeonExpress/Shipping/
```

### 2. Install the PHP SDK

The module requires the official Pigeon Express PHP SDK.

**Via Composer** (if the SDK directory is available locally):

```bash
composer require pigeonexpress/php-sdk:*
```

Or copy the contents of the SDK `src/` directory manually into:

```
lib/internal/PigeonExpress/
```

Expected structure:

```
lib/internal/PigeonExpress/
├── PigeonExpress.php
├── Configuration.php
├── DTO/
├── Exceptions/
├── HttpClient/
├── Resources/
└── Contracts/
```

### 3. Register the SDK namespace

Add the `PigeonExpress` namespace to your root `composer.json` autoload section:

```json
"autoload": {
    "psr-4": {
        "Magento\\Framework\\": "lib/internal/Magento/Framework/",
        "PigeonExpress\\": "lib/internal/PigeonExpress/"
    }
}
```

Then regenerate the autoloader:

```bash
composer dump-autoload
```

### 4. Enable the module

```bash
php bin/magento module:enable PigeonExpress_Shipping
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

---

## Configuration

Go to **Stores → Configuration → Sales → Shipping Methods → Pigeon Express**.

| Field | Description |
|-------|-------------|
| Enable | Enable/disable the carrier |
| API Key | Your Pigeon Express API key (`pk_live_...`) |
| API Secret | Your Pigeon Express API secret (`sk_live_...`) |
| Test Mode | Use sandbox API endpoint |
| Enable Logging | Log API requests to `var/log/pigeonexpress.log` |

### Delivery Types

Three delivery types can be enabled independently: **Address**, **Office**, **APS (Parcel Locker)**. Each supports:

- **Fixed pricing** — flat rate set in admin
- **Dynamic pricing** — rate calculated via Pigeon Express API based on package dimensions, weight, and destination

### Place of Shipment (Pickup)

Configure where you hand over parcels to Pigeon Express:

- **Office** — select your pickup office from the dropdown (populated after syncing locations)
- **Address** — enter your pickup address city ID, street, and number

### Package Dimensions

For dynamic pricing, map your product attributes for length, width, and height (in cm) under the **Package Dimensions** section.

---

## Location Sync

Offices and APS lockers are stored locally in the database and used for:
- Checkout autocomplete (customer picks a delivery location)
- "Pickup office" dropdown in admin config

### Automatic sync

A daily cron job runs at **2:00 AM** to sync all locations from the Pigeon Express API:

```
job_code: pigeonexpress_sync_locations
group:    pigeonexpress
schedule: 0 2 * * *
```

> **Important:** The `pigeonexpress` cron group must be running on your server. If you run cron with `--group default` only, this job will not execute. Either run `php bin/magento cron:run` without a group flag, or add `php bin/magento cron:run --group pigeonexpress` to your cron schedule.

### Manual sync (admin button)

To sync locations immediately without waiting for the daily cron:

1. Go to **Stores → Configuration → Sales → Shipping Methods → Pigeon Express**
2. Click **"Schedule Sync Now"** under the *Location Sync* label
3. A `cron_schedule` record is created with `status = pending`
4. The sync runs on the next cron tick (typically within 1 minute)

If a sync is already pending, clicking the button shows a notice instead of creating a duplicate.

After a successful sync, the **Pickup office** dropdown will be populated and offices/APS lockers will appear in checkout autocomplete.

---

## Logs

When logging is enabled, all API requests and errors are written to:

```
var/log/pigeonexpress.log
```

Successful sync example:

```
[PigeonExpress Cron] Start location sync
[PigeonExpress] API request: GET /offices?type=office&page=1&per_page=100
[PigeonExpress Cron] Sync done. Offices: created=42, updated=0, deactivated=0 | APS: created=15, updated=0, deactivated=0
```

---

## Order Confirmation Email

The module includes a custom order confirmation email template that displays Pigeon Express delivery details (delivery type, location name, address, instructions, delivery price).

### Setup (required)

1. Go to **Stores → Configuration → Sales → Sales Emails → Order**
2. Set **"New Order Confirmation Template"** to **"Pigeon Express Order Confirmation"**
3. Set **"New Order Confirmation Template for Guest"** to **"Pigeon Express Order Confirmation (Guest)"**
4. Save and flush the cache

> **Important:** Without this step, orders will use the default Magento template and no Pigeon Express delivery details will appear in the email.

### If the template does not appear in the dropdown

After installing the module and running `setup:upgrade` + `cache:flush`, the templates should appear automatically. If they do not, create them manually in the admin:

1. Go to **Marketing → Communications → Email Templates**
2. Click **"Add New Template"**
3. In the **"Load default template"** section:
   - **Template** → select **"Pigeon Express Order Confirmation"** (or **"Pigeon Express Order Confirmation (Guest)"** for the guest variant)
   - Click **"Load Template"**
4. Set **Template Name** to `Pigeon Express Order Confirmation` (or `Pigeon Express Order Confirmation (Guest)`)
5. Click **"Save Template"**
6. Repeat steps 2–5 for the guest variant
7. Then follow the setup steps above to assign the templates in **Sales → Sales Emails → Order**

---

## Troubleshooting: Shipping Methods Not Appearing

If no Pigeon Express methods appear at checkout, enable logging (`Stores → Configuration → Sales → Shipping Methods → Pigeon Express → Enable Logging`) and check `var/log/pigeonexpress.log`. The most common causes:

### 1. Carrier is disabled

`Stores → Configuration → Sales → Shipping Methods → Pigeon Express → Enable` must be set to **Yes**.

---

### 2. All delivery types are disabled

At least one of **Address**, **Office**, or **APS** must be enabled. Check each type has **Enable** set to **Yes**.

---

### 3. Dynamic pricing — API key not configured

**Symptom in log:**
```
Pigeon Express API key is not configured.
Skipping delivery type due to error
```

**Fix:** Enter your API Key and API Secret in the carrier configuration. Switch to **Fixed** pricing if you do not have API credentials yet.

---

### 4. Dynamic pricing — product dimensions not configured

**Symptom in log:**
```
Missing product dimensions for dynamic rate {"length":null,"width":null,"height":null}
Dynamic rate unavailable due to missing product dimensions
Skipping delivery type due to error
```

**Fix — option A (recommended for testing):** Switch pricing mode to **Fixed** and set a flat rate.

**Fix — option B:** In the carrier config, section **Package Dimensions**, map your Magento product attributes for length, width, and height (in cm). Then make sure the actual products in the cart have those attribute values filled in.

---

### 5. Dynamic pricing — pickup location not configured

**Symptom in log:**
```
Pickup type is Office but no pickup office selected in config; rate unavailable.
```
or
```
Place of shipment: pickup address city is not configured.
```

**Fix:** In the carrier config, section **Place of Shipment**:
- If **Pickup Type** = `Office` → select your pickup office from the **Pickup Office** dropdown (run location sync first if the list is empty)
- If **Pickup Type** = `Address` → fill in **City ID**, **Street**, and **House Number**

---

### 6. Dynamic pricing — delivery city not found (location sync not run)

**Symptom in log:**
```
Pigeon Express city not found for "...". Please run location sync to update city list.
```

**Fix:** Run location sync — either wait for the daily cron or click **Schedule Sync Now** in the carrier config. See the [Location Sync](#location-sync) section.

---

### 7. Office/APS location not found in database

**Symptom in log:**
```
Could not resolve delivery location to API id
```

**Fix:** The customer selected a location that is no longer in the local database. Run location sync to refresh the list.

---

### Quick checklist

| Check | Where |
|-------|-------|
| Carrier enabled | Shipping Methods → Pigeon Express → Enable |
| At least one delivery type enabled | Same page, Address / Office / APS section |
| Pricing mode | Fixed (no API needed) or Dynamic (requires API key + product dimensions) |
| API key set | Carrier config → API Key |
| Product dimensions mapped | Carrier config → Package Dimensions |
| Pickup location configured | Carrier config → Place of Shipment |
| Location sync done | Click "Schedule Sync Now" or wait for cron |

---

## Delivery Flow

1. Customer selects a Pigeon Express shipping method at checkout
2. For **Office** or **APS** delivery — an autocomplete input appears to search and select a location
3. Selected location is saved to the quote and persisted to the order
4. Location details are included in the order confirmation email
5. Admin opens the order and clicks **"Send to Pigeon Express"** to dispatch the shipment to the PE API
6. A shipment record is created with reference number, tracking number, and full API payload/response

---

## Shipment Management

After an order is placed, the admin can send it to the Pigeon Express API to create a shipment record.

### Sending an order

1. Open the order in **Sales → Orders → [order]**
2. Click **"Send to Pigeon Express"** in the Pigeon Express delivery info block
3. The module calls the PE API, creates a shipment, and stores the result
4. On success: reference number, tracking number, and delivery price are displayed in the order view
5. On error: an error message is shown — check `var/log/pigeonexpress.log` for details

The shipment data (API request payload and response) is stored in `pigeonexpress_shipment` for audit purposes.

---

## Database Tables

| Table | Description |
|-------|-------------|
| `pigeonexpress_office` | Synced Pigeon Express offices |
| `pigeonexpress_aps` | Synced APS parcel lockers |
| `pigeonexpress_city` | Synced city reference data (used for dynamic rate city lookup) |
| `pigeonexpress_quote_address` | Selected PE delivery location per quote shipping address |
| `pigeonexpress_order_address` | Selected PE delivery location + price per order shipping address |
| `pigeonexpress_shipment` | Shipment records: reference number, tracking number, API payload & response |
| `sales_order` (column) | `pigeonexpress_cod_fee` — COD fee amount stored with the order |
