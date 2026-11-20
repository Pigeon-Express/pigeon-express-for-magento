# PigeonExpress Shipping: Magento 2 Module

Magento 2 shipping carrier integration for [Pigeon Express](https://pigeonexpress.com) courier service. Supports delivery to address, office, and APS parcel lockers with fixed or dynamic (API-based) pricing.

---

## Features

- Delivery to an address, an office or a parcel locker, with fixed or dynamic
  (API-based) pricing.
- Cities, streets, offices and lockers chosen from the carrier's lists at
  checkout, with working hours.
- Optional nearest offices and lockers at checkout, and an optional map to
  choose from.
- Shipments created from the order screen, automatically, or from the orders
  grid, with labels, edits, cancels, returns and courier requests.
- Quick changes after the courier has the parcel (delivery place, services,
  cash on delivery, declared value) with the price shown before you confirm.
  When Pigeon Express answers with a new waybill, the order moves to it and the
  customer is emailed the new number.
- Parcel locker free boxes shown in the order form.
- Status, charges and tracking in the order and the orders grid.

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

The module requires the official Pigeon Express PHP SDK. Place it at:

```
packages/pigeonexpress-php-sdk/
```

relative to your Magento root, so that `packages/pigeonexpress-php-sdk/src/`
contains `PigeonExpress.php`. The module registers an autoloader for that path,
so no changes to your root `composer.json` are needed.

Expected structure:

```
packages/pigeonexpress-php-sdk/
└── src/
    ├── PigeonExpress.php
    ├── Configuration.php
    ├── Contracts/
    ├── DTO/
    ├── Exceptions/
    ├── HttpClient/
    └── Resources/
```

Guzzle, the SDK's only other dependency, already ships with Magento.

### 4. Enable the module

```bash
php bin/magento module:enable PigeonExpress_Shipping
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

---

## Configuration

Go to **Stores → Configuration → Sales → Delivery Methods → Pigeon Express**.

Settings are grouped into collapsible sections. **Enable** sits at the top and
applies to the whole carrier.

| Section | Contains |
|---------|----------|
| API and environment | Test mode, and the API key and secret for each environment |
| General | Carrier title and name, error message, country restrictions, hide contents on the label |
| Send From | Where your parcels enter the Pigeon Express network: office, address, saved address or parcel locker (with an office for parcels that do not fit) |
| Courier request | Defaults for courier requests: time window, same-day cut-off, contact. Shown only when the courier collects from you |
| Checkout and cash on delivery | Which payment methods are cash on delivery, the COD fee at checkout, who pays |
| Additional Services | The optional services your account includes: inspection, return receipts, declared value |
| Address / Office / Parcel Locker Delivery | Enable, title, pricing mode, flat rate, fallback price, free shipping threshold, working hours |
| Returns | Return at your expense, where returned shipments go, return shipments from the order |
| Labels | Label contents and format, create shipments automatically |
| Shipment status and tracking | Status checks, what happens to the order when delivered or returned, Magento shipment |
| Parcel weight and dimensions | Attribute mapping, defaults, fragile packages, package description |
| Sync and diagnostics | Manual sync trigger, logging |

The Checkout and cash on delivery section has two optional settings, both off
by default:

- **Nearest office**: when a customer chooses office or parcel locker
  delivery, the browser asks to share their location and the five nearest
  offices or lockers are listed first. A customer who declines sees the usual
  list.
- **Map of offices and lockers**: adds a "Choose on the map" button to the
  list. See External services below.

Only additional services included in your Pigeon Express account are shown. A
service your account does not have is hidden rather than offered as a switch that
does nothing.

Prices for additional services, including the cash on delivery fee, are set by
Pigeon Express and read from the API. They are not configurable here.

### Delivery Types

Three delivery types can be enabled independently: **Address**, **Office**, **APS (Parcel Locker)**. Each supports:

- **Fixed pricing**: flat rate set in admin
- **Dynamic pricing**: rate calculated via Pigeon Express API based on package dimensions, weight, and destination
- **Free shipping threshold**: an order subtotal, after discounts, at or above
  which that delivery type costs nothing. Configured per type, because each one
  is priced separately. Off by default.
- **Sort order**: the position of that type among the Pigeon Express options at
  checkout. Lower shows first.

Magento's own **Free Shipping** cart price rule is honoured too, whatever the
thresholds are set to. Magento only reports a rule to the carrier when the whole
cart qualifies, so a rule that frees part of a cart leaves the rate unchanged: a
single parcel cannot be half free.

When a delivery is free, the cash on delivery fee is waived with it, and the
waybill is billed to the sender even where **Who Pays** is set to Receiver.
Otherwise the courier would collect at the door for a delivery the shop said was
free. An explicit Who Pays choice on the shipment screen still wins.

### Label Contents

Pigeon Express prints the parcel contents on the waybill, and by default that is
the product names and quantities from the order. Everyone who handles the parcel
can read it.

**Label Contents** under General switches this to a single **Fixed Description**
you choose, for example `Стоки`. The whole order becomes one line with no
quantity, so the label reads `Съдържание: Стоки` rather than
`Съдържание: 5 бр. - Push It Messenger Bag 1kg`.

Nothing here moves money. The sum the carrier insures is sent separately as
`declared_value` (the order total less delivery, kept between 1 and 5,000
EUR unless an amount is typed on the order), and cash on delivery as
`cod_amount`, so both are unaffected by what the label says.

Defaults to product names, so upgrading does not change what your labels say.
Leave the description empty and the label falls back to a generic word, never to
the product name.

### Pickup Location

Configure where you hand over parcels to Pigeon Express:

- **Office**: select your pickup office from the dropdown (populated after syncing
  locations). **You must take every parcel to that office yourself.** No courier
  will collect from your address while this option is selected.
- **Address**: enter your pickup address city ID, street, and number

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

1. Go to **Stores → Configuration → Sales → Delivery Methods → Pigeon Express**
2. Click **"Schedule Sync Now"** under the *Location Sync* label
3. A `cron_schedule` record is created with `status = pending`
4. The sync runs on the next cron tick (typically within 1 minute)

If a sync is already pending, clicking the button shows a notice instead of creating a duplicate.

After a successful sync, the **Pickup office** dropdown will be populated and offices/APS lockers will appear in checkout autocomplete.

---

## External services

Besides the Pigeon Express API, two optional checkout features involve other
parties. Both are off until you switch them on.

- **Map (OpenStreetMap)**: when a customer opens "Choose on the map", their
  browser loads the map images from tile.openstreetmap.org. Their browser
  address and the area they look at are visible to that service, as for any
  OpenStreetMap map. The shop sends nothing about the customer or the order.
  The office and locker positions come from the shop's own data. With both
  Nearest office and the map on, the map opens on the customer's area (when
  they allowed their location), so the map image requests show that area to
  openstreetmap.org.
- **Nearest office**: the customer's browser shares their location only after
  they allow it. The location is rounded to about 100 metres and sent to
  Pigeon Express to find the nearest offices or lockers. It is not stored with
  the order or the cart and is not written to the logs.

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

Pigeon Express delivery details (delivery type, location name and address, and
the customer's note) are added to the order confirmation email automatically.

No configuration is required, and no email template needs to be selected. The
details are published through Magento's own `shipping_msg` variable, which the
standard order email renders beneath the shipping method. This means the block
appears in the default template and in any template you have customised, and it
survives Magento upgrades.

If the details do not appear, check that the order actually used a Pigeon Express
shipping method, and that your order email template still renders
`{{var shipping_msg}}`.

## Troubleshooting: Shipping Methods Not Appearing

If no Pigeon Express methods appear at checkout, enable logging (`Stores → Configuration → Sales → Delivery Methods → Pigeon Express → Enable Logging`) and check `var/log/pigeonexpress.log`. The most common causes:

### 1. Carrier is disabled

`Stores → Configuration → Sales → Delivery Methods → Pigeon Express → Enable` must be set to **Yes**.

---

### 2. All delivery types are disabled

At least one of **Address**, **Office**, or **APS** must be enabled. Check each type has **Enable** set to **Yes**.

---

### 3. Dynamic pricing, API key not configured

**Symptom in log:**
```
Pigeon Express API key is not configured.
Skipping delivery type due to error
```

**Fix:** Enter your API Key and API Secret in the carrier configuration. Switch to **Fixed** pricing if you do not have API credentials yet.

---

### 4. Dynamic pricing, product dimensions not configured

**Symptom in log:**
```
Missing product dimensions for dynamic rate {"length":null,"width":null,"height":null}
Dynamic rate unavailable due to missing product dimensions
Skipping delivery type due to error
```

**Fix, option A (recommended for testing):** Switch pricing mode to **Fixed** and set a flat rate.

**Fix, option B:** In the carrier config, section **Package Dimensions**, map your Magento product attributes for length, width, and height (in cm). Then make sure the actual products in the cart have those attribute values filled in.

---

### 5. Dynamic pricing, pickup location not configured

**Symptom in log:**
```
Pickup type is Office but no pickup office selected in config; rate unavailable.
```
or
```
Pickup location: pickup address city is not configured.
```

**Fix:** In the carrier config, section **Pickup Location**:
- If **Pickup Type** = `Office` → select your pickup office from the **Pickup Office** dropdown (run location sync first if the list is empty)
- If **Pickup Type** = `Address` → fill in **City ID**, **Street**, and **House Number**

---

### 6. Dynamic pricing, delivery city not found (location sync not run)

**Symptom in log:**
```
Pigeon Express city not found for "...". Please run location sync to update city list.
```

**Fix:** Run location sync, either wait for the daily cron or click **Schedule Sync Now** in the carrier config. See the [Location Sync](#location-sync) section.

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
| Carrier enabled | Delivery Methods → Pigeon Express → Enable |
| At least one delivery type enabled | Same page, Address / Office / APS section |
| Pricing mode | Fixed (no API needed) or Dynamic (requires API key + product dimensions) |
| API key set | Carrier config → API Key |
| Product dimensions mapped | Carrier config → Package Dimensions |
| Pickup location configured | Carrier config → Pickup Location |
| Location sync done | Click "Schedule Sync Now" or wait for cron |

---

## Delivery Flow

1. Customer selects a Pigeon Express shipping method at checkout
2. For **Office** or **APS** delivery, an autocomplete input appears to search and select a location
3. Selected location is saved to the quote and persisted to the order
4. Location details are included in the order confirmation email
5. Admin opens the order, checks the shipment form, and sends it to Pigeon Express
6. A shipment record is created with reference number, tracking number, and full API payload/response

---

## Shipment Management

After an order is placed, the admin can send it to the Pigeon Express API to create a shipment record.

### Sending an order

1. Open the order in **Sales → Orders → [order]**
2. Check the **Create Pigeon Express Shipment** form. It is filled in from the
   order, and anything that will not do is marked with the reason
3. Correct anything that needs it. The City and Street fields search Pigeon
   Express as you type; choosing from the list is what attaches the carrier's own
   identifier to the waybill
4. Press **Send to Pigeon Express**
5. On success: reference number, tracking number, and delivery price are displayed in the order view
6. On error: the form comes back with your values and the reason, and nothing was sent

Corrections apply to that shipment only. The order keeps the address the customer
entered, so it stays on record if the delivery is later disputed.

This matters more than it looks. Pigeon Express identifies a recipient by phone
number, and an address block it cannot resolve is quietly replaced with whatever
address it already holds for that number, so the parcel goes elsewhere while the
label reads correctly.

The shipment data (API request payload and response) is stored in `pigeonexpress_shipment` for audit purposes.

### After the shipment is created

- **Status**: the order screen shows the carrier's status, with Refresh. The
  status of every open shipment is also checked twice a day. Under **Shipment
  status and tracking**, "When the shipment is delivered" can complete the
  order (shipped in Magento if it is not yet, and invoiced offline when it is
  cash on delivery; other unpaid orders get a note to invoice by hand), and
  "When the shipment comes back" can put the order on hold or cancel it (an
  order with an invoice only gets a note).
- **Magento shipment and tracking**: a Magento shipment with the tracking number
  is created with the waybill, so customers get the tracking link in My Account
  and in emails.
- **Edit**: until the courier collects it, the receiver, address, packages,
  cash on delivery and services can be changed without cancelling. Print the
  label again after a change.
- **Charges**: what Pigeon Express charges for the shipment and who pays it.
- **Cancel**: until the courier collects it.

### Orders grid

**Sales > Orders** has a **Pigeon Express** column with the waybill and its
status, and "Courier requested" while a courier request for it is open. Its
filter shows orders delivered, in delivery, returned or refused, waiting for
the courier, or with no shipment. Mass actions create shipments, refresh their
status, download labels as one ZIP and request a courier.

### Automatic creation

With **Create shipments automatically** (Labels) on, a shipment is created when
an order becomes Processing. A shipment is never sent to a street that was
guessed: when the address does not match the carrier's lists, the order gets a
comment and the shipment waits for you.

### Return shipments

The order screen has a **Return shipment** section: the customer sends the
parcel back from an address, an office or a parcel locker, to an office or to
you. Switch it off under Returns.

### Courier requests

When the courier collects from your address, **Request a courier** on the order
screen and the orders grid mass action book a pickup. Requests are listed under
**Sales > Operations > Pigeon Express courier requests**, where they can be
refreshed or cancelled.

---

## Database Tables

| Table | Description |
|-------|-------------|
| `pigeonexpress_office` | Synced Pigeon Express offices |
| `pigeonexpress_aps` | Synced APS parcel lockers |
| `pigeonexpress_city` | Synced city reference data (used for dynamic rate city lookup) |
| `pigeonexpress_quote_address` | Selected PE delivery location per quote shipping address |
| `pigeonexpress_order_address` | Selected PE delivery location + price per order shipping address, and whether free shipping is why it cost nothing |
| `pigeonexpress_shipment` | Shipment records: reference number, tracking number, API payload & response, last status and charges |
| `pigeonexpress_auto_create` | Orders waiting for automatic shipment creation, and how each run ended |
| `pigeonexpress_return_shipment` | Return shipments created from orders |
| `pigeonexpress_courier_request` | Courier requests made from this shop (the API cannot list them) |
| `pigeonexpress_courier_request_shipment` | The shipments in each courier request |
| `pigeonexpress_additional_service` | Additional services catalogue synced from the API, plus the last fee the API charged for each |
| `sales_order` (column) | `pigeonexpress_cod_fee`: COD fee amount, as charged by the API, stored with the order |
