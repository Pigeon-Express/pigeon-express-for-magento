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
| 3 | `ShipmentSender` sends `cod_amount` and `sms_notification` at the top level instead of inside `service_codes`. The spec puts both inside. The courier may never be told to collect the money, on a real order | S | Open |
| 4 | Quote and shipment disagree on package geometry. `RateCollector` sums dimensions across items and splits at 30 kg, `ShipmentSender` takes the first product and builds one package. Customer is quoted one thing, the waybill says another | M | Open |
| 5 | `Carrier::getAllowedMethods()` returns `[code => "address,office,aps"]` instead of code to title. Breaks anything iterating allowed methods, including some third party extensions | S | Open |
| 6 | COD fee is folded into `shipping_amount` after tax and never into `shipping_incl_tax`. The collector is registered for `quote` only, so admin order creation does not apply it | M | Partly, `fetch()` now returns the segment |
| 7 | `Controller/Checkout/SetLocation.php` writes an unvalidated `location_id`, city and postcode straight onto the quote from request input | S | Open |
| 8 | Street lookup in `AddressResolver` is unfiltered and unpaginated, so it fails for any large city | S | Open |

Item 3 is the most serious one still open. It is a money bug on live orders and
is a small change.

## P2, missing capability

| # | Item | Effort |
|---|------|--------|
| 9 | Honour `option_group`. Services in the same group are mutually exclusive and the module can currently send two at once, which the API rejects | S |
| 10 | Use the stored `input_type` to decide which service codes carry a value, instead of the hardcoded assumption | S |
| 11 | Restrict services per delivery type. A locker cannot do ID verification or a paper receipt. WooCommerce models this, Magento does not | M |
| 12 | No label retrieval. `GET /v1/shipments/{ref}/label` is unused, so merchants cannot print from Magento | M |
| 13 | No cancellation. `POST /v1/shipments/{ref}/cancel` is unused | S |
| 14 | No tracking refresh. `GET /v1/shipments/{ref}/track` and the bulk variant are unused, and there is no tracking UI | M |
| 15 | No COD payout reconciliation. `GET /v1/payments/completed` is unused | M |
| 16 | No `external_reference` on created shipments. One line, and a prerequisite for reconciliation | S |
| 17 | No courier request support. All three `/v1/courier-requests` operations are unused | M |
| 18 | Two SDK resources point at endpoints that do not exist and will 404: `/v1/offices/search` (8 methods) and `/v1/streets/search` (3). Eleven of 24 public SDK methods aim at nothing | M |
| 19 | Zero tests, zero static analysis, zero CI. Every P1 above is a silent wrong number that a unit test would have caught | L |

## P3, hygiene

| # | Item | Effort |
|---|------|--------|
| 20 | `AddressResolver` and `ShipmentClient` use raw Curl and bypass the SDK. Retries, timeouts and error mapping are inconsistent as a result | M |
| 21 | Emails are switched by reflecting into a private core property. Fragile across upgrades | M |
| 22 | Inline `<script>` in an admin block, against Magento CSP guidance | S |
| 23 | Six dead JS files under `view/frontend/web/js` | S |
| 24 | No last sync timestamp surfaced in admin. Staleness is invisible to the merchant | S |
| 25 | API responses are not cached. Rate lookups hit the API on every quote recalculation | M |
| 26 | `requires_special_packaging` is mapped in config but absent from the live catalogue, so it is silently dropped. Either remove it or get it enabled on the account | S |
| 27 | i18n coverage is partial. Several admin strings are not translatable | S |
| 28 | No `CHANGELOG.md` | S |

## Suggested order

1. Item 3, then 4. Both are money bugs on real orders.
2. Items 9 and 10, small and they make the service catalogue genuinely useful.
3. Item 19. Without tests the next fee style regression will ship unnoticed.
4. Items 12 to 16 as a batch, they are the visible feature gap against competitors.
