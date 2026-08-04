# Changelog

All notable changes to this module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
