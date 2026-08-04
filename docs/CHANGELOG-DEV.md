# Changelog, development record

The full engineering history, including why each defect existed and what it did
before it was fixed. This file is **internal**: `docs/` is outside the
`SHIP_PATHS` whitelist in `bin/build-zip.sh`, so it cannot reach a client zip.

The client facing summary is `CHANGELOG.md` at the module root, which does ship.
Keep both in step: a change worth a line there is worth the full account here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-08-04

### Added

- **A free shipping threshold for each delivery type.** An order subtotal, after
  discounts, at or above which that type is carried for nothing. Configured per
  type rather than once for the carrier, because the three are priced separately
  and a figure that makes sense for a locker rarely makes sense for a door. The
  subtotal compared is the one every core carrier compares, so a coupon that
  takes a cart under the threshold takes the free delivery with it. A threshold
  left empty is no threshold: it would otherwise read as zero and give the shop
  away the moment someone ticked the box without filling the amount in. The
  amount is cleared on save when the toggle goes off, so an old figure cannot sit
  in the database and reappear months later.
- **Magento's own Free Shipping cart price rule is honoured.** The rule flag has
  been on every rate request the whole time and the carrier never read it, so a
  merchant could write the rule, watch it appear in the cart, and still be
  charged for delivery. It now frees every enabled type, whatever the thresholds
  say. Magento only reports the rule to a carrier once the entire cart qualifies,
  so a rule covering part of a cart leaves the rate alone: one parcel cannot be
  half free.
- **A sort order for each delivery type.** Less cosmetic than it sounds. Magento
  hands every rate from every carrier through `sortRatesByPrice()` before the
  checkout sees any of them, so the order the carrier quotes in has never
  survived, and the three options have always been listed cheapest first. With a
  threshold configured that becomes visible: the list rearranges itself as the
  cart crosses the threshold and one option drops to nothing. The configured
  order is now restored after Magento has finished, leaving every other carrier
  where it was. Worth recording that the ordering being overridden was never
  correct anyway, `sortRatesByPrice()` comparing prices as strings with
  `natsort()`, which puts 4.20 before 4.15.

- **A rate of ours that costs nothing reads "Free" rather than 0.00.** In the
  checkout method list, the cart page estimator and the order summary line. A
  price of 0.00 reads as a missing value or a bug, and this module hides a method
  it cannot price rather than quoting zero, so a zero is always deliberate and
  worth saying out loud. Only Pigeon Express rates: every other carrier keeps
  Magento's formatting. The word is translated, so a Bulgarian storefront reads
  "Безплатно". Note the checkout row template is now installed whether or not a
  carrier logo is configured, having previously been tied to one.

- **The label need not say what is in the parcel.** Pigeon Express prints the
  contents on the waybill, so "5 бр. - Push It Messenger Bag 1kg" is read by the
  courier, by the counter staff, and by whoever is standing behind the recipient
  when they collect it. **Label Contents** in the general settings can replace
  every product name with one wording of the merchant's choosing. The whole order
  becomes a single line with no quantity, so the label reads "Съдържание: Дрехи"
  rather than "Съдържание: 5 бр. - Push It Messenger Bag 1kg": repeating one
  wording once per order line says nothing, and a count beside it reads as though
  something has been lost. Nothing here moves money. `inventory_items` is
  descriptive only; the sum the carrier insures travels as `declared_value` from
  the order subtotal and the sum it collects as `cod_amount`, both untouched.
  Defaults to product names, so an upgrade does not silently change anyone's
  labels. An empty field falls back to a generic word rather than to the product
  name, since falling back to the name would quietly undo the only thing the
  setting does.

- Product descriptions are now trimmed to the 200 characters the API accepts. A
  longer one was previously sent whole, and the carrier rejects the shipment
  rather than trimming it.

- **The shipment summary splits the delivery cost by who pays it.** One
  "Shipping fee" row became "Shipping fee (Sender)" and "Shipping fee
  (Receiver)", read from the side the form actually bills rather than from the
  store setting, so it follows the switch to the sender that a free delivery
  makes on the merchant's behalf. The side that is not billed reads zero rather
  than being hidden, a row that vanishes looking like missing data when which way
  round it is is the whole question. A billed side with nothing charged reads
  "Calculate" instead of 0.00: the only figure an order carries is what the
  customer paid, which on a free delivery is nothing while the carrier still
  invoices the merchant, and the module never asked what for, a giveaway being
  quoted without calling the API. Printing 0.00 there would state a cost that is
  about to arrive on an invoice, and fetching it on sight would put a carrier
  round trip, with retries, inside an admin page render. Recalculate answers it on
  demand and now reports both sides too.

- **The shipment screen says when a delivery was given away.** A note beside Who
  Pays, on those orders only, explaining that free shipping applied at checkout
  and that the carrier therefore bills the merchant. Without it the control
  simply does not show the configured store setting, which reads as the setting
  having been ignored.

### Changed

- **The cash on delivery fee is waived when the delivery itself was free.**
  Previously it was added on top of whatever the shipping line held, zero
  included, so a delivery advertised as free still arrived at checkout with a fee
  attached to it. The test is simply whether anything was charged to carry the
  parcel, which covers a threshold, a cart price rule and a flat rate a merchant
  deliberately set to zero without having to tell them apart.
- **A free delivery is billed to the merchant even where Who Pays is Receiver.**
  Receiver pays has the courier collect the delivery charge at the door, which
  would undo the giveaway at the last possible moment and in front of the
  customer. Free shipping changes the fallback only: an admin who picks a side on
  the shipment screen still gets the side they picked, a screen saying one thing
  while the carrier is told another being worse than the charge it was avoiding.
  Which orders were given away is recorded when the order is placed rather than
  worked out later, both because a threshold the merchant changes next week must
  not rewrite who was billed for an order already taken, and because a zero
  shipping amount cannot answer the question on its own: a store on receiver pays
  quotes zero on purpose and does expect the courier to collect.
- The cash on delivery collector moved from sort order 450 to 455. Magento's own
  tax collector is also 450 and the fee has to land after it, tax having already
  derived the tax inclusive shipping figure from the pre fee amount. A tie
  resolves to whichever collector happens to be merged first, which is not
  something to leave money resting on.

### Fixed

- `priceFor()` asked which side pays without saying which store it was asking
  about, so on a multi store install it read the default scope rather than the
  store the order belongs to.
- APS had no default for recipient inspection, unlike address and office
  delivery.

## [1.1.0] - 2026-07-30

### Fixed

- **None of the module's checkout settings reached the checkout.** The config
  provider was registered in the module's global di.xml, but Magento_Checkout
  declares the whole provider list in its own frontend di.xml, and an area
  definition beats a global one for that argument, so the entry was dropped
  without complaint. Everything the browser reads from it was therefore
  permanently absent: Override Address Fields did nothing whichever way it was
  set, and the stock street, city and postcode were never hidden. It is
  registered in the frontend di.xml now.
- **Most of the stock address stayed on screen with the override on.** The
  fields were hidden by a stylesheet matching classes Luma does not put on them:
  in the checkout an address field carries no `city` or `postcode` class, only a
  name attribute, so only the street wrapper, which is a fieldset and does carry
  one, ever disappeared. City, postal code, region and country sat there in full
  view beside the carrier's own. They are hidden through each field's own
  visible observable now, which is how the office and locker case had been doing
  it all along. The stray second street line that case left behind is gone with
  them.
- **Checkout would move on from an empty street.** Two faults, either enough on
  its own. The stock street was still being validated while hidden, so its
  complaint rendered inside a hidden element and stopped the step without
  showing anything; and the carrier's fields only ever wrote a street they had
  one for, so a street the customer typed and then deleted stayed behind in the
  field that reaches the order, passing a check the visible form should have
  failed. The stock fields now mirror the carrier's exactly, empty included, and
  the carrier's fields are checked in their own right when the step is
  submitted, with the message shown against the field it belongs to.
- **The delivery details in the order email were shown as raw markup.** The
  customer was reading `<p class="pigeonexpress-delivery-email"><strong>` and the
  rest of it. Magento reads this back with `{{var shipping_msg}}`, which escapes
  whatever it is given, in both strict and legacy template modes, so tags put
  there never styled anything: they were printed. It is plain text now, and no
  longer escaped here either, which would have turned an ampersand in a street
  name into `&amp;` once the template escaped it a second time. The carrier and
  the delivery type went with the markup, Magento printing the shipping
  description directly above and that already reading "Pigeon Express - Office
  Delivery"; saying it again is how the message came to be four lines long.
- **No order could be placed at all: "regionId is required".** Bulgaria is one
  of the countries in Magento's `general/region/state_required` list, so an
  address without a region is refused when the order is submitted. Hiding the
  stock region field left it empty, and nothing the customer chose supplied one,
  a settlement not being a region. Pigeon Express does hold the district each
  settlement belongs to, and those are the same 28 names Magento ships as
  Bulgarian regions, so the region is now taken from there: 5,277 of the 5,284
  settlements resolve. The seven with no district on record get the stock region
  field back to fill in, rather than an order that fails on being placed.
  Requires one location sync, which the upgrade does not do for you.
- **Collection orders carried the customer's own street address.** Choosing an
  office or a locker set the address on the quote but left Magento's own fields
  holding whatever had been typed for a door delivery, and it is those that
  reach the order, the invoice and the confirmation email. They now hold the
  counter, written as "(Office) name, address" or "(Parcel locker) name,
  address": the type is said out loud because the address of a locker is the
  address of the supermarket it stands outside, and nothing else on the order
  would say the parcel is not being carried to a door.
- **A postal code the carrier does not hold left an order that could not be
  placed.** Pigeon Express has no code on record for two dozen of the smallest
  settlements, and Magento will not take a Bulgarian address without one. The
  box is opened for the customer to fill in where that happens, and stays read
  only everywhere else.
- **The office and locker search could never match on a city name.** The filter
  read a column the location sync never writes, so it only ever appeared to work
  because the city name happens to sit inside the free-text address. It reads the
  joined city table now.
- **A carrier that could not be reached offered free shipping.** Any failure to
  price a delivery type fell back to its flat rate, and a store on dynamic
  pricing keeps no flat rate: the field is hidden and cleared on save, so it is
  always zero. An API outage, an unconfigured pickup office, a parcel with no
  tariff, all of them quoted 0.00 and sold the delivery for nothing, silently. A
  delivery type that cannot be priced is now hidden instead, and the flat rate is
  used only where one is actually set.
- **The check that hides a method for missing dimensions never fired in a
  translated admin.** It matched on the text of its own error message, so it
  worked in English and nowhere else. On a Bulgarian store the parcel locker
  method was quoted at 0.00 instead of being hidden whenever a product had no
  dimensions. It is a distinct exception type now, and the text is irrelevant.
- **Every label download failed.** Preview worked, but choosing any format from
  the download menu, and ZPL by any route, bounced back to the order screen with
  "Could not load the Pigeon Express label". The helper used to send the file
  returns a response object where the controller declares a result object, so the
  type error was caught by the surrounding handler and reported as an API
  problem. Labels are now streamed directly, which also stops a copy of every one
  being written into var/ on the way past. Each format arrives under its own
  filename, so downloading two of them no longer produces a file and a copy.
- **Parcel limits are no longer written into the module at all.** One figure was
  applied to everything: 30 kg per parcel and 200 cm on every axis. A 35 kg cart
  was therefore declared as two parcels and priced as two, which the customer
  paid for, and a 220 cm parcel was declared 20 cm shorter than it is. The limits
  are now learned from Pigeon Express and remembered: a refusal names the limit it
  refused on, and a parcel that prices proves the ceiling is at least that high. A
  tariff written above today's ceiling, or the 250 cm rule being dropped, is
  picked up on the next quote without a release.
- **A parcel too big for a locker is now refused instead of resized.** The API
  does not check the compartment, so an oversized parcel was accepted, dispatched,
  and would not go in. It is measured as a box rather than axis by axis, because a
  parcel can be turned round.
- One waybill is capped at ten parcels, which is what Pigeon Express allows. Past
  that the order is refused with a notice saying how many parcels it needs, rather
  than being split across two reference numbers.
- A parcel the carrier will not take now says so at checkout instead of the
  delivery method quietly disappearing from the list.
- **One heavy item could be declared as two parcels.** Splitting was pure
  arithmetic, so a single 41 kg item became two of 20.5 kg. Nobody can hand the
  courier half an item, and the waybill promised two parcels where one would
  arrive. Splitting is now bounded by how many items there are, and where a single
  item exceeds the carrier limit the shipment is refused with an explanation
  rather than invented. Checkout hides the method instead of quoting for it.
- **A waybill's weight came from the catalogue, not from the order.** Editing a
  product's weight retroactively changed what past orders said they weighed, so
  an order placed at 1 kg could be sent to the carrier as 41 kg. Shipments now use
  the weight recorded on the order. Rating still reads the catalogue, because a
  cart is live.
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
- **Deliveries could go to the wrong address.** Pigeon Express identifies a
  recipient by phone number, and replaces an address it cannot resolve with
  whatever it already holds for that number. The module made that likely: it
  searched for streets using the prefix the customer typed ("ул. Витоша"), while
  the carrier stores the bare name ("ВИТОША") with the type in a separate field,
  so the match almost always failed on real orders. The address then travelled as
  a free text note and the carrier substituted its own. Street prefixes are now
  stripped before lookup, the street is also sent by name, the recipient is named
  on the address itself, and block, entrance, floor and apartment are sent as
  structured fields instead of being buried in a note.
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

- **A shipment form on the order screen, instead of a single Send button.** The
  recipient, phone, city, street, number, block, entrance, floor, apartment,
  postal code and courier note are laid out as ordinary fields, filled in from
  the order, and anything that will not do is marked with the reason. The city
  and street fields search Pigeon Express as you type, so choosing from the list
  attaches the carrier's own identifier and there is nothing to guess at. An
  admin who has just phoned the customer can correct the address and send.
  Corrections apply to that shipment only and never overwrite what the customer
  entered. A rejected form comes back with the values still in it.
- Where two streets in a city share a name, which happens in София, the type the
  customer wrote decides between them, and where it cannot, the form says so
  rather than picking one.
- **A parcel can be redirected when the customer rings up.** The delivery type is
  the first control on the shipment form, so an order placed for home delivery
  can be sent to an office or a parcel locker instead. The address fields give way
  to a picker for the counter, searched against the same synced list checkout
  uses. The order keeps what the customer chose and was charged; only the waybill
  changes.
- **Who pays the shipping** is now a setting rather than always the sender, both
  as a store default and per shipment on the order form. It defaults to Receiver.
  Note that Pigeon Express quotes the same price either way and Magento still
  charges whatever the shipping rate says, so with Receiver the courier collects
  the delivery charge on top of anything already billed. A store that does not
  want that should zero its own rate for the affected delivery types, or set this
  to Sender.
- **Checkout can ask for the address the way the carrier holds it.** With
  Override Address Fields on, a customer choosing address delivery picks their
  city and street from Pigeon Express's own lists rather than typing them, with
  block, entrance, floor and apartment beside them. Magento's own street, city,
  postcode, region and country give way to it; name, company, email and
  telephone stay, nothing here collecting those, and the telephone least of all,
  it being how Pigeon Express identifies a recipient. The chosen values are
  written into the stock fields rather than replacing them, so tax, invoices,
  emails and the address book all still read the same address the waybill will.
  The form is laid out as the order screen's is, on the same twelve column grid,
  without the help icons: those explain the carrier to a member of staff, and a
  shopper is owed a form that needs no explaining. It ships off, because it
  changes what every customer sees.
- **Offices and lockers are chosen by city first.** One search box across the
  whole country either buried the right counter or hid it behind a result limit:
  Sofia alone has 43 offices and 84 lockers. Now the city comes first, offering
  only cities that actually have somewhere to collect from, and then every pickup
  point in that city, filterable but never truncated. It sits in the same panel
  and on the same grid as the carrier's address fields, so the two read as one
  thing rather than as two features that arrived separately. Both fields are on
  the form from the start, the second saying what it is waiting for rather than
  appearing once the first is answered. Each pickup point is listed as its name
  above its address rather than as one run-on line, thirty of those being
  unreadable at a glance, and the one chosen is set apart from the boxes above
  it, being an answer rather than a question.
- A carrier logo can be shown beside the methods at checkout, chosen from the
  four Pigeon Express supplies. No logo by default. The two drawn in white say so
  in the setting, and get a dark plate behind them so they are not invisible.
- The form ends with a summary of what actually goes on the waybill: parcel
  weight, dimensions and piece count by the same rules used for rating, the
  amount the courier is to collect, and the shipping fee.
- **Recalculate** asks Pigeon Express what the carriage costs for the destination
  currently on the form. The existing fee is relabelled as the old one and the
  new figure is added beneath it, rather than replacing it. Redirecting a parcel
  changes one and not the other, and that difference is the thing worth seeing.
  It saves nothing and changes no total.
- The label can be downloaded in any of the four formats Pigeon Express produces:
  its default, thermal 100 x 150 mm, an A4 sheet, and Zebra ZPL for a thermal
  printer. Preview stays a single click on the default one.
- A cancelled waybill is struck through in the shipment list rather than being
  distinguishable only by reading its status.

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
- Shipments can be cancelled from the order screen while Pigeon Express still
  allows it, which is before the courier collects the parcel. Once collected the
  button is replaced by an explanation, rather than failing at the API.
- After a cancellation the order can be shipped again. A new waybill is created
  alongside the cancelled one rather than replacing it, so the order screen shows
  both and nothing is lost from the record.
- The shipping label can be opened from the order screen and printed or saved
  from the browser. It is fetched on demand rather than stored, so it is always
  the current one.
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
- Debug logging from the checkout components. Every location choice, every
  address field written and every placeholder cleared was printed to the
  browser console of every shopper.

## [1.0.0] - 2026-06-25

### Added
- Initial release of the Pigeon Express shipping module for Magento 2
- Address, office and parcel locker delivery
- Dynamic and flat rate pricing per delivery type
- Office, locker and city synchronisation
- Waybill creation from the admin order view
- Cash on delivery support
