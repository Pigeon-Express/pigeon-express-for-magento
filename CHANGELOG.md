# Changelog

All notable changes to this module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- API key, secret and test mode are global settings.
- Offices, lockers and cities are kept separately for test and live.
- The waybill COD amount is the order total the customer saw, discounts
  included, less the shipping charge when the receiver pays the courier.
- Orders paid online default to Sender pays with no COD.
- The checkout COD fee is calculated on the goods total after discounts.
- Products without a weight or size share one default parcel per shipment by
  default. See How to Apply the Defaults.

### Added

- Show COD Fee: shows the COD fee next to COD payment methods at checkout.
- How to Apply the Defaults: once per shipment, always one default parcel, or
  for every item.
- Add COD Fee to Flat Rate, per delivery type, for the Fixed pricing mode.
- Parcel and payment section on the admin shipment form: COD value, total
  weight, number of parcels and parcel size. Edits are kept after a cancel.
- Separate API keys for the test and live environments.
- Settings grouped by task: API and environment, Send From, Checkout and cash
  on delivery, Returns, Labels, Shipment status and tracking, and more.
- Fragile packages and a package description per package.
- Inspection before payment, return receipts and a declared value amount per
  shipment.
- Office and parcel locker working hours at checkout.
- Fallback price when a rate cannot be calculated.
- Hand shipments in at a parcel locker, with an office for the ones that do
  not fit.
- Pickup from a saved address in your Pigeon Express account.
- Choose where returned shipments go, and return at your expense.
- Automatic shipment creation when an order is Processing.
- City and street chosen from the carrier's lists at checkout.
- Several packages per shipment on the order screen.
- Option to hide the contents on the label.
- Shipment status in the order and in the orders grid, with a filter.
- Optionally complete the order when the shipment is delivered (cash on
  delivery orders are invoiced), and put it on hold or cancel it when the
  shipment comes back.
- Magento shipment and tracking number created with the waybill, and a
  tracking link for customers.
- Edit a shipment without cancelling it.
- Shipment details with the charges from Pigeon Express.
- Orders grid mass actions: create shipments, refresh status, download labels
  and request a courier.
- Return shipments created from the order.
- Nearest office and parcel locker at checkout, found from the customer's
  location (optional, off by default).
- "Choose on the map" at checkout: offices and lockers on an OpenStreetMap
  map (optional).
- Quick changes after the courier has the parcel: delivery place, services,
  cash on delivery and declared value, with the price shown first.
- A redirect moves the order to the new waybill, replaces the tracking number
  on the Magento shipment and emails the customer the new number.
- Parcel locker free boxes shown in the order form.
- Receiver name and phone can be changed from the order form.
- Returns and redirects say why they cannot be cancelled.
- Return to a saved address, and a separate place for return documents.
- Editing a shipment keeps the recorded return destination.
- Streets are matched by postcode when the city has several streets with the
  same name.
- Request a courier from an order or the orders grid, and manage requests
  under Sales > Operations > Pigeon Express courier requests. The orders grid
  shows "Courier requested" and filters orders waiting for the courier.

### Fixed

- Location sync runs when the carrier is enabled in any store.
- Choosing a COD payment method did not add the COD fee.
- Recalculate on the admin shipment form left out COD.
- Bug fixes.

## [1.2.1] - 2026-10-13

### Fixed

- Bug fixes in pickup location.

## [1.2.0] - 2026-08-04

First public release.

### Delivery

- Three delivery types, each enabled and priced independently: **Address**,
  **Office** and **Parcel locker (APS)**.
- **Dynamic pricing** quoted by the Pigeon Express API from the parcel's weight,
  dimensions and destination, or a **fixed rate** per delivery type.
- A delivery type the carrier cannot price, or will not carry, is hidden rather
  than offered at zero.
- **Sort order** per delivery type, controlling the order the options appear in
  at checkout.

### Free shipping

- A **free shipping threshold** per delivery type: an order subtotal, after
  discounts, at or above which that type costs nothing. Off by default.
- Magento's own **Free Shipping cart price rules** are honoured, whatever the
  thresholds are set to.
- A free delivery shows as "Free" at checkout rather than 0.00, and the cash on
  delivery fee is waived with it.
- Where a delivery was given away, the waybill is billed to the sender even if
  Who Pays is set to Receiver, so the courier does not collect at the door for
  something the shop gave away. An explicit choice on the shipment screen still
  wins.

### Checkout

- Office and parcel locker pickers, searching offices, lockers and cities held
  locally rather than calling the carrier on every keystroke.
- Optionally replaces Magento's street, city and postcode fields with the
  carrier's own lists, so an address always matches what Pigeon Express holds.
- Carrier logo at checkout, in four variants.

### Payment and services

- **Cash on delivery**, with the fee the API charges. No rate is ever hardcoded:
  every figure comes from the carrier.
- Additional services, offered only where the account actually carries them:
  declared value, signature on delivery, return receipt, return documents,
  special packaging and recipient inspection.

### Orders and shipments

- Create a waybill from the order screen, with the recipient's address checked
  against the carrier's own city and street lists before it is sent.
- Reprice a shipment before creating it, so a redirected parcel's cost is visible
  in advance.
- Retrieve labels and cancel shipments.
- **Label Contents**: print a single description of your choosing on the waybill
  instead of the product names, so a parcel does not announce what a customer
  bought to everyone who handles it.

### Setup

- Nightly sync of offices, parcel lockers and cities, with a button to run it on
  demand.
- Product attributes for length, width and height, with fallbacks for products
  that have none.
- Full Bulgarian translation.

### Requirements

- Magento Open Source or Adobe Commerce 2.4.x
- PHP 8.1, 8.2 or 8.3
- A Pigeon Express API key and secret
