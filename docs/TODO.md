# Gaps and improvements

Derived from a full audit of the module against the published OpenAPI spec, the
WooCommerce reference implementation, and Magento 2.4.7 practice. Line
references are to the state before the API driven fee work.

Priorities: **P1** wrong behaviour reaching customers, **P2** missing capability
or real risk, **P3** hygiene.

## P1, correctness

| # | Item | Effort | Status |
|---|------|--------|--------|
| 1 | COD fee charged twice: the rate payload included `cod_amount` so the API already billed it, then the total collector added a hardcoded 2.5 percent on top | M | **Done** |
| 2 | SDK dropped `total_service_fees`, so the API's real fee could not reach Magento at all | L | **Done** |
| 3 | `ShipmentSender` sent `cod_amount` and `sms_notification` at the top level instead of inside `service_codes`. `CreateShipmentRequest` has neither field, so the courier was never told to collect the money | S | **Done** |
| 4 | Quote and shipment disagreed on package geometry. Rating summed dimensions across lines and split at 30 kg, the waybill took the first product with dimensions and built one package with a hardcoded 10x10x10 fallback | M | **Done** |
| 5 | `Carrier::getAllowedMethods()` returned `[code => "address,office,aps"]` instead of code to title | S | **Done** |
| 6 | COD fee was folded into `shipping_amount` after the tax pass and never into `shipping_incl_tax`, so every tax inclusive display understated the charge | M | **Done** |
| 7 | `Controller/Checkout/SetLocation.php` wrote an unvalidated `location_id`, city and postcode straight onto the quote from request input | S | **Done** |
| 8 | Street lookup in `AddressResolver` was unfiltered and unpaginated, so it failed for any large city | S | **Done** |

All P1 items are now closed. Notes on how a few were resolved:

- Item 4 was fixed by extracting `Model/Package/PackageBuilder.php`, now the only
  place that decides weight, dimensions and splitting. Rating behaviour is
  unchanged on purpose, so existing quotes keep their prices; shipment creation
  was brought into line with it. Do not reintroduce a second copy of these rules.
- Item 6: the audit also claimed the collector never runs during admin order
  creation. That is not correct. `Magento\Sales\Model\AdminOrder\Create` calls
  `$quote->collectTotals()`, which uses the `quote` totals section, so the
  collector does run. Only the `shipping_incl_tax` half was a real defect.
  The fee is added at face value and is therefore not itself taxed. If Pigeon
  Express expects VAT on the COD fee, that is a separate decision and needs the
  collector to run before the tax pass rather than after.
- Item 7 now resolves the location against our own synced tables and takes name,
  address, city and postcode from there. The sync leaves `city` and `postcode`
  empty on every office and locker but always fills `city_id`, so those two
  values come from a join to `pigeonexpress_city`.
- Item 3 drops any service code the API does not currently offer, which is why
  locker shipments no longer send an SMS flag: `sms_notification_receiver` is not
  in the demo account's catalogue. See item 26.

## P2, missing capability

| # | Item | Effort | Status |
|---|------|--------|--------|
| 9 | Honour `option_group`. Services in the same group are mutually exclusive and the module can currently send two at once, which the API rejects | S | **Done** |
| 10 | Use the stored `input_type` to decide which service codes carry a value, instead of the hardcoded assumption | S | **Done** |
| 11 | Restrict services per delivery type. A locker cannot do ID verification or a paper receipt. WooCommerce models this, Magento does not | M | **Done** |
| 12 | No label retrieval. `GET /v1/shipments/{ref}/label` is unused, so merchants cannot print from Magento | M | Deferred |
| 13 | No cancellation. `POST /v1/shipments/{ref}/cancel` is unused | S | Deferred |
| 14 | No tracking refresh. `GET /v1/shipments/{ref}/track` and the bulk variant are unused, and there is no tracking UI | M | Deferred |
| 15 | No COD payout reconciliation. `GET /v1/payments/completed` is unused | M | Deferred |
| 16 | No `external_reference` on created shipments. One line, and a prerequisite for reconciliation | S | Deferred |
| 17 | No courier request support. All three `/v1/courier-requests` operations are unused | M | Deferred |
| 18 | Two SDK resources point at endpoints that do not exist and will 404: `/v1/offices/search` (8 methods) and `/v1/streets/search` (3). Eleven of 24 public SDK methods aim at nothing | M | **Done** |
| 19 | Zero tests, zero static analysis, zero CI. Every P1 above is a silent wrong number that a unit test would have caught | L | **Done** |

Item 18 was a prerequisite for item 20: `LocationsClient` bypassed the SDK
specifically because `all()` ignored query parameters and so could neither
paginate nor filter by type. Fixing the SDK removed the reason to bypass it.

## P3, hygiene

| # | Item | Effort | Status |
|---|------|--------|--------|
| 20 | `AddressResolver` and `LocationsClient` used raw Curl and bypassed the SDK, so retries, timeouts and error mapping were inconsistent. The audit named `ShipmentClient` here, but that one already used the SDK | M | **Done** |
| 21 | Emails are switched by reflecting into a private core property. Fragile across upgrades | M | **Done** |
| 22 | Inline `<script>` in an admin block, against Magento CSP guidance | S | **Done** |
| 23 | Six dead JS files under `view/frontend/web/js` | S | **Done** |
| 24 | No last sync timestamp surfaced in admin. Staleness is invisible to the merchant | S | **Done** |
| 25 | API responses are not cached. Rate lookups hit the API on every quote recalculation | M | **Done** |
| 26 | `requires_special_packaging` and `sms_notification_receiver` are mapped in the module but absent from the account's catalogue, so both are silently dropped. Either remove the mappings or get the services enabled | S | **Done** |
| 27 | i18n coverage is partial. Several admin strings are not translatable | S | **Done** |
| 28 | No `CHANGELOG.md` | S | **Done** |

## What is left

Every P1, P2 and P3 item above is closed. The only work still outstanding is the
courier operations set, which needs an admin surface the module does not have and
so was deliberately deferred to its own release with a design pass:

| # | Item | Effort |
|---|------|--------|
| 12 | Label retrieval, so merchants can print from Magento | M | Deferred |
| 13 | Shipment cancellation | S | Deferred |
| 14 | Tracking refresh and a tracking UI | M | Deferred |
| 15 | Cash on delivery payout reconciliation | M | Deferred |
| 16 | `external_reference` on created shipments, a prerequisite for 15 | S | Deferred |
| 17 | Courier request support | M | Deferred |

Also still missing, and worth doing before those:

- **Integration tests.** Unit coverage is good now, but nothing exercises the
  checkout location chain (eleven plugins carrying a chosen office through to the
  order address) or proves end to end that a cash on delivery order lands on the
  right total. Those need a database.
- **`RateCollector::buildCalculatePayload()` is still untested.** It is the most
  important method in the module and it is private, large, and reaches into the
  checkout session. Extracting the payload assembly would make it testable.
- **Two services are mapped but unavailable.** `requires_special_packaging` and
  `sms_notification_receiver` are not in the account catalogue, so they are
  skipped and logged, and the admin panel now says so. Either ask Pigeon Express
  to enable them or drop the mappings.
