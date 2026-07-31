# Changelog

All notable changes to this module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-07-30

### Fixed

- **Cash on delivery was overcharged.** The fee was hardcoded at 2.5 percent of
  the subtotal with a 0.80 minimum, while Pigeon Express charges 1.5 percent. On
  top of that the fee was applied twice: once by the API inside the quoted
  shipping price, and again by the module. The fee is now whatever the API
  charges, applied once. If it cannot be obtained, no fee is charged and the
  reason is logged, so a stale figure can never reach a customer.
- **Shipments did not tell the courier to collect the money.** The cash on
  delivery amount was sent as a top level field, which the create-shipment
  endpoint does not have. It is now inside `service_codes` as documented. The
  same applied to the locker SMS notification, which additionally used a service
  code that does not exist.
- **Quotes and waybills described different parcels.** Rating summed dimensions
  across order lines and split the cart at 30 kg, while the waybill took the
  first product that happened to have dimensions and declared a single parcel
  with a fallback of 10x10x10 cm. Both now use the same rules, and the rules are
  the ones rating already used, so existing quotes keep their prices.
- **Tax inclusive shipping understated the charge.** The fee was added after the
  tax pass and never reached `shipping_incl_tax`, so any tax inclusive display of
  shipping was short by the fee.
- `getAllowedMethods()` returned the carrier code pointing at a comma joined
  string instead of a map of method code to title, which breaks callers that
  iterate it or look a method up by code.
- Street lookup failed in any large city. It fetched one unfiltered page and
  searched it locally, so a street beyond the first hundred was never found. It
  now uses the API's own name filter with pagination.
- The delivery location submitted at checkout is validated against the synced
  offices and lockers. City and postcode are taken from that record rather than
  from the request, and the courier note is bounded.
- Additional services are no longer requested when the delivery method cannot
  perform them. A parcel locker has nobody present to verify identity, hand over
  a paper receipt or return signed documents.
- Services that the merchant's Pigeon Express account does not include are
  skipped and logged rather than sent and rejected.
- Two mutually exclusive services can no longer be requested together.
- Location syncing refuses to run when the API returns a fraction of the records
  already stored, instead of deactivating most of the merchant's pickup points.

### Added

- Additional services catalogue, synced nightly from the API alongside offices,
  lockers and cities, and stored with the last fee the API charged for each
  service so an outage falls back to a real figure rather than a guess.
- Recipient inspection is now a choice between inspection only and inspection
  plus testing, which are two separately priced services. Previously only the
  second was reachable. Existing configuration keeps ordering exactly what it
  ordered before.
- Insurance (declared value) is now a simple Yes or No. When enabled, the value
  of the goods in the order is sent as the sum insured. Previously it required
  typing a fixed amount, which insured every parcel for the same figure
  regardless of what was in it.
- Configuration is now grouped into collapsible sections (General, API Setup,
  Additional Services, one per delivery type, Pickup Location, Product Weight
  and Dimensions, Location Sync) instead of one long list. Every setting keeps
  the value it already had.
- Additional services your Pigeon Express account does not include are hidden
  from the configuration entirely, rather than offered as a switch that silently
  does nothing.
- Flat Rate Price is only shown for a delivery type that is actually priced with
  a fixed rate, and is cleared when the type is switched back to dynamic pricing,
  so a stale amount cannot reappear later.
- The Pickup Location section now states plainly that choosing an office means
  you take your parcels to that office yourself, since no courier collects from
  your address in that mode.
- Quoted prices are cached briefly, which removes the repeated identical API
  calls Magento's checkout previously made on every totals recalculation.
- Unit test suite and static analysis.

### Changed

- Every Pigeon Express API call now goes through the SDK. Two clients previously
  used raw HTTP with their own retry and error handling.
- Delivery details reach the order confirmation email through Magento's own
  `shipping_msg` variable. The module previously replaced the entire order email
  with a copy of Magento's, which discarded any customisation a merchant had made
  to that email.
- The searchable office selector no longer uses an inline script, for Content
  Security Policy compliance.

### Removed

- The unused cash on delivery checkout display components. The fee has always
  been folded into the shipping amount, and these never rendered.

## [1.0.0] - 2026-06-25

### Added
- Initial release of the Pigeon Express shipping module for Magento 2
- Address, office and parcel locker delivery
- Dynamic and flat rate pricing per delivery type
- Office, locker and city synchronisation
- Waybill creation from the admin order view
- Cash on delivery support
