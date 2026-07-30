# WooCommerce plugin as reference implementation

`pigeon-express-for-woocommerce` 1.0.2 is the more mature of the two
integrations and is the behavioural reference for this module. This document
records what it does, so Magento work has a target to match. Full source
analysis lives in `.ai/woocommerce-plugin-analysis.md`.

## The rule it gets right

**The WooCommerce plugin contains zero hardcoded fees.** No percentage, no
minimum, no arithmetic on money anywhere in its PHP or JS. It reads exactly one
monetary field from the API, `data.total_price`, and hands it to WooCommerce as
the shipping rate.

Enabling an additional service simply changes the request body. The carrier
reprices, the new `total_price` becomes the rate, and the fee appears without
the plugin ever knowing what it was. When a customer switches off COD the fee
disappears because the shipment is re-quoted, not because anything is subtracted
locally.

Magento now follows the same principle, with one deliberate difference: Magento
shows the COD fee as its own line and stores it on the order, so it prices the
shipment twice and takes the difference. See
[PRICING-AND-SERVICES.md](PRICING-AND-SERVICES.md).

## Dynamic versus stored

| Data | Where it lives |
|------|----------------|
| Shipping price | Live API call per quote, never stored |
| Additional service fees | Live, folded into `total_price`, never stored |
| Offices and lockers | Custom DB tables, refreshed daily by cron |
| Service catalogue | `pigeon_express_wc_active_services` option, refreshed daily |
| Cities and streets | Live per keystroke in admin, never stored |
| Which services apply to which delivery method | Hardcoded PHP constant, see below |

## What it hardcodes, and whether we should copy it

Three things are hardcoded in the WooCommerce plugin. Two are legitimate, one
is a wart.

1. `APPLICABLE_SERVICES` per shipping method class. Address and Office allow
   nine codes, APS allows only four, because a locker has no human to verify ID
   or hand over a paper receipt. This is a real business constraint, not a
   price. Magento should express the same restriction.
2. The mapping from an internal code to a payload field, in `BodyBuilder::services()`.
   Unavoidable glue.
3. `service_type` is always the literal `'standard'`. Express is never offered.
   Magento does the same. Both should eventually expose the choice.

## Feature inventory

Checkout:
- Three delivery methods: address, office, APS locker
- Office and locker pickers with search
- Live rate per quote

Admin, order screen:
- Meta box listing the additional services applicable to the chosen method
- Per order overrides of which services apply
- COD amount defaults to the order total, editable
- Manual waybill creation

Admin, settings:
- Credentials with validation on save, which triggers the first sync
- Sender details
- Per method enable and configuration
- Service toggles

Background:
- Daily sync of offices, lockers and the service catalogue
- Last sync timestamps per dataset

Labels and emails:
- Waybill creation and label handling
- Order email integration

## Divergences from the published API, do not copy these

The WooCommerce plugin drifted from the OpenAPI spec in eight ways. The ones
that matter when porting:

- It calls `/services`, the documented endpoint is `/additional-services`.
- It sends `sms_notification`, the catalogue lists `sms_notification_receiver`.
- It reads `group`, the spec field is `option_group`, so mutually exclusive
  service groups are invisible to it. Magento should honour `option_group`.
- It ignores `input_type` and hardcodes which codes carry a numeric value.
  Magento stores `input_type` in `pigeonexpress_additional_service` and should
  use it.
- Its weight and dimension caps (40 kg, 250 cm) exceed the documented limits
  (30 kg, 200 cm). Magento's 30 kg splitting is correct.

## Known defects in the reference

Do not port these:

- A partial location sync can replace the full local dataset with the partial
  one, losing offices. Magento's sync deactivates missing rows instead.
- If `declared_value` and COD are both enabled they receive the same figure,
  because one `$cart_total` parameter is reused for both.
- Tracking is dead code. `get_shipment_tracking()` has no callers and there is
  no tracking UI.
