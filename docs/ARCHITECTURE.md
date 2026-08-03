# Architecture

`PigeonExpress_Shipping` is a Magento 2.4.7 carrier module, roughly 10k lines,
backed by `pigeonexpress/php-sdk`. It offers three delivery types: to address,
to office, and to APS (parcel locker).

## Request flow

```
Carrier\PigeonExpress::collectRates()
  `-- one rate per enabled delivery type
        `-- Rate\RateCollector::getRatePrice()
              |-- pricing mode flat?  -> config flat rate, no API call
              `-- pricing mode dynamic
                    |-- buildCalculatePayload()  packages, dimensions, weight,
                    |                            pickup from config,
                    |                            delivery from the quote
                    |-- Api\RateClient::calculate()  POST /v1/shipments/calculate
                    `-- resolveCodFee()              second call, COD delta
```

Checkout adds a location picker on top: `Plugin\Checkout\LayoutProcessorPlugin`
injects the KO components, and the customer's chosen office or locker travels
`checkout JS -> ShippingInformationInterface extension attributes -> quote
address -> pigeonexpress_quote_address -> order address ->
pigeonexpress_order_address`. Eleven plugins and two observers hold that chain
together, which is the most fragile part of the module.

On order placement `Observer\Sales\OrderPlaceAfter` hands off to
`Model\ShipmentSender`, which builds the waybill and posts `POST /v1/shipments`.

## Layers

| Layer | Key classes |
|-------|-------------|
| Carrier and rating | `Model/Carrier/PigeonExpress.php`, `Model/Rate/RateCollector.php`, `Model/Rate/LocationToApiIdResolver.php` |
| API clients | `Model/Api/RateClient.php`, `LocationsClient.php`, `AdditionalServicesClient.php`, `ShipmentClient.php`, `AddressResolver.php`. All go through the SDK |
| Shipment form | `Model/Shipment/AddressForm.php` decides what is good enough to ship. `Model/Api/AddressResolver.php` answers the lookups it asks. `Controller/Adminhtml/Order/CitySearch.php` and `StreetSearch.php` serve the pickers |
| Pricing of services | `Model/Service/CodFeeResolver.php`, `Model/Service/ServiceCodesBuilder.php`, `Model/Total/Quote/CodFee.php`, `Model/AdditionalServiceRepository.php` |
| Package geometry | `Model/Package/PackageBuilder.php`, the only place weight, dimensions and parcel splitting are decided |
| Persistence | `etc/db_schema.xml`, `Model/ResourceModel/*` |
| Sync | `etc/crontab.xml`, `etc/cron_groups.xml`, `Model/Cron/SyncLocations.php`, `Model/LocationSync.php` |
| Checkout frontend | `view/frontend/web/js/**`, `Plugin/Checkout/*` |
| Admin config | `etc/adminhtml/system.xml`, `Block/Adminhtml/System/Config/*`, `Model/Config/Source/*` |
| Order lifecycle | `Observer/*`, `Plugin/Quote/*`, `Plugin/Sales/*`, `Model/ShipmentSender.php` |

## Tables

| Table | Purpose |
|-------|---------|
| `pigeonexpress_office` | Offices, synced nightly. `api_id` is the API id, `entity_id` is local |
| `pigeonexpress_aps` | Parcel lockers, same shape |
| `pigeonexpress_city` | Cities, used to resolve a quote city to an API `city_id` |
| `pigeonexpress_additional_service` | Service catalogue plus the last API charged fee per service |
| `pigeonexpress_quote_address` | Chosen delivery location per quote shipping address |
| `pigeonexpress_order_address` | Chosen delivery location per order shipping address |
| `pigeonexpress_shipment` | Waybill records: reference number, tracking number, payload, response |
| `sales_order.pigeonexpress_cod_fee` | COD fee as charged by the API for that order |

Watch the id distinction. Quote and order rows store the local `entity_id`;
the API needs `api_id`. `LocationToApiIdResolver` translates, and getting this
backwards is a recurring source of bugs.

## Sync

One cron job, `pigeonexpress_sync_locations`, in its own `pigeonexpress` group,
nightly at 02:00. It syncs offices, lockers, cities and the additional services
catalogue. The admin "Schedule Sync Now" button does not run anything directly,
it inserts a pending `cron_schedule` row that the next cron tick picks up.

CLI PHP on this host has `disable_functions=exec`, which breaks multi group
cron, so run it as:

```bash
php8.3 -d disable_functions= -d memory_limit=-1 bin/magento cron:run --group=pigeonexpress
```

## Where the module talks to the API

Only two endpoints are used in normal operation, plus the catalogue sync:

- `POST /v1/shipments/calculate` for rates and for service fees
- `POST /v1/shipments` to create the waybill
- `GET /v1/additional-services`, `GET /v1/offices`, `GET /v1/cities` for sync

The SDK's office, city and street resources now match the published API and
paginate properly. Endpoints still unused by both: label retrieval,
cancellation, tracking, completed payments and courier requests. See
[TODO.md](TODO.md).

## Conventions

- Business logic belongs in `Model/`, never in a template or a JS component.
  The COD fee was previously computed in three places including the browser.
- All API access goes through the SDK, behind an interface in `Api/` with a
  preference in `etc/di.xml`. The SDK owns pagination, retries and rate limit
  back-off, so none of that should be reimplemented here.
- Anything decided in more than one place has drifted at least once in this
  module's history: package geometry, additional service codes and street search
  each existed twice and each pair disagreed. `PackageBuilder` and
  `ServiceCodesBuilder` exist to keep them single. Do not add a second copy.
- Log through `PigeonExpress\Shipping\Logger\Logger`, gated on
  `isLoggingEnabled()`. Output lands in `var/log/pigeonexpress.log`.
- Money never appears as a literal. See [PRICING-AND-SERVICES.md](PRICING-AND-SERVICES.md).
- Quoted prices are cached for 15 minutes, keyed on a hash of the calculate
  payload. Raising that window recreates the stale pricing this module was fixed
  to remove.
