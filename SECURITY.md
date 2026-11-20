# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in this module, please report it
**privately**. Do **not** open a public GitHub issue for security problems.

- Email: **dev@pigeonexpress.bg** (subject: `SECURITY`)
- Please include: a description, steps to reproduce, the affected version, and
  the potential impact.

We will acknowledge your report within **5 business days** and provide a
remediation timeline after triage. Please give us a reasonable opportunity to
fix the issue before any public disclosure.

## Supported Versions

| Version | Supported |
|---------|-----------|
| 1.x     | ✅         |

## Credentials & secrets

Pigeon Express API credentials (`pk_…` / `sk_…`) are configured at **runtime**, in the Magento admin (Stores → Configuration → Sales → Shipping Methods → Pigeon
Express) and stored encrypted in the Magento database. **No secrets are stored in
this repository**, and none should ever be committed. The carrier writes API
diagnostics to `var/log/pigeonexpress.log` (outside this repo); avoid pasting full
request/response payloads containing credentials into issues or PRs.

## Endpoints and permissions

Checkout (storefront):

- `pigeonexpress/checkout/nearest` accepts POST only, with the page's form key
  and only from a session that has a cart, and only while the "Nearest office"
  setting is on. It is throttled per cart, per client address and for the whole
  shop, and backs off after the carrier says it is busy. The coordinates are
  rounded, used for that one lookup, and are never stored with the quote or the
  order, never logged and never returned.
- `pigeonexpress/checkout/maplocations` accepts GET and returns the public
  office and locker data (name, address, position) from the local tables. It
  holds nothing about the customer.

Admin (all POST, with the admin form key, and each runs under the order's lock
so it cannot overlap a create, edit, cancel or another change):

- `QuickOptions` and `QuickChange` need the ACL resource
  `PigeonExpress_Shipping::quick_change` ("Make Quick Changes to a Pigeon
  Express Shipment").
- `LockerBoxes` needs `PigeonExpress_Shipping::send_shipment`.

The read-only office, locker, city and street lookups (`LocationSearch`,
`CitySearch`, `StreetSearch`, GET) need `PigeonExpress_Shipping::send_shipment`
or `PigeonExpress_Shipping::quick_change`, as both the shipment form and the
change of delivery place use them.

Redirects: when Pigeon Express redirects a shipment, the module follows only the
link the carrier returns on the shipment the order already holds
(`related_shipments`), a limited number of steps, and moves the order only when
the new shipment can be read back. Nothing a customer or an admin types chooses
the new number.

Content Security Policy: the module whitelists `tile.openstreetmap.org` for
`img-src` so the optional checkout map can show its images. No other host is
added. With both "Nearest office" and the map on, the map opens on the
shopper's area once they allow their location, so the OpenStreetMap image
requests (made by the shopper's browser) reveal that area to openstreetmap.org.
