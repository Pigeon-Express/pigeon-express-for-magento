# QA test plan, release 1.1.0

Manual test plan for the Pigeon Express Magento module, version 1.1.0. Covers
everything that changed since 1.0.0.

Read **Known expected behaviour** at the bottom first. Several things look like
bugs and are not, and one of them is the most likely false report.

---

## 1. Environment

| | |
|---|---|
| Storefront | https://pigeonexpress.softart.work/magento/ |
| Admin | https://pigeonexpress.softart.work/magento/admin |
| HTTP basic auth | `dev` / `softart` (whole site, before Magento login) |
| Store currency | EUR |
| API | Demo (`api-demo.pigeonexpress.com`), Test Mode is on |
| Configured COD method | Cash on Delivery |
| Configured pickup office | 149 |

**Main config path**, referred to below as **[CONFIG]**:
`Stores > Configuration > Sales > Delivery Methods > Pigeon Express`

**Log**, ask the developer to tail this while you test. Several cases can only be
confirmed from it:
`magento_app/var/log/pigeonexpress.log`

Cache after any config change: `System > Tools > Cache Management > Flush Magento Cache`

---

## 2. Pre-test setup

| # | Step | Path |
|---|------|------|
| 0.1 | Confirm the carrier is enabled and Test Mode is on | **[CONFIG]** > Enable = Yes, then API Setup > Test Mode = Yes |
| 0.2 | Confirm all three delivery types are enabled and set to Dynamic | **[CONFIG]** > Address / Office / APS Delivery: Enable = Yes, Pricing Mode = Dynamic |
| 0.3 | Confirm COD Payment Methods contains Cash on Delivery | **[CONFIG]** > Additional Services > COD Payment Methods |
| 0.4 | Confirm Enable Logging is Yes | **[CONFIG]** > API Setup > Enable Logging / Error Handling |
| 0.5 | Confirm Cash on Delivery is an active payment method | `Stores > Configuration > Sales > Payment Methods > Cash On Delivery Payment` |

---

## 3. Admin configuration

### A1. Configuration is grouped into collapsible sections · P1

**Path:** **[CONFIG]**.

**Expect:** one always visible **Enable** switch, then nine collapsible sections
in this order: General, API Setup, Pickup Location, Additional Services, Address
Delivery, Office Delivery, Parcel Locker (APS) Delivery, Product Weight and
Dimensions, Location Sync. Each expands and collapses on click.

**Fail if:** the page is one long flat list, or a section will not open.

### A2. Existing settings survived the regrouping · P1, critical

The fields moved into sections in this release. Their stored values must not
have moved with them.

**Path:** **[CONFIG]**, open every section in turn.

**Expect:** API Key and API Secret still populated, Test Mode still Yes, the
pickup office still selected, COD Payment Methods still Cash on Delivery, the
weight and dimension attributes still mapped, and every delivery type still
enabled with its pricing mode intact.

**Fail if:** any field reads as empty or reset to a default. That would mean a
config path changed and the merchant silently lost a setting.

### A2b. Only services on the account are shown · P1

**Path:** **[CONFIG]** > **Additional Services**.

**Expect:** toggles for Paper Return Receipt, ID Verification and Document
Signature, Digital Return Receipt, Service Return Documents and Insurance.
**Special Packaging must not appear at all**, because this account does not
include it.

**Fail if:** Special Packaging is visible. A switch that silently does nothing is
the thing this replaced.

### A3. Recipient inspection has three options · P1

**Path:** **[CONFIG]** > **Address Delivery** > **Recipient Inspection**, and the same
field under **Office Delivery**. Labels no longer repeat the section name.

**Expect:** exactly three options, `No`, `Inspection only`, `Inspection and testing`.
The field label should say Recipient Inspection, not "Review and Test".

**Fail if:** only two options, or the old label.

### A4. Existing inspection setting was preserved · P1

**Path:** same field.

**Expect:** whatever was set before the upgrade still reads as
**Inspection and testing** (not blank, not reset to No). Confirm with the
developer what it was set to before.

**Fail if:** the value reset. That would silently change what the merchant is billed.

### A5. Searchable pickup office selector still works · P2

**Path:** **[CONFIG]** > **Pickup Location** > **Pickup Type** = Office > **Office**.

**Expect:** a search box above the dropdown. Typing filters the list live. The
currently saved office stays selected while you type. No JavaScript errors in the
browser console.

**Fail if:** the search box does nothing, or the console shows a Content Security
Policy violation. The behaviour moved out of an inline script in this release.

### A5b. Flat Rate Price follows Pricing Mode · P1

**Path:** **[CONFIG]** > **Office Delivery**.

**Expect:** with **Pricing Mode** = `Dynamic (via API)` there is **no Flat Rate
Price field**. Switch to `Fixed (flat rate override)` and it appears immediately.

**Then:** enter `9.99`, save. Switch Pricing Mode back to Dynamic, save, switch to
Fixed again. **The price must be empty, not 9.99.** A hidden price that quietly
comes back months later is the thing this prevents.

Repeat for Address Delivery and Parcel Locker.

### A5c. Pickup Location explains the office obligation · P2

**Path:** **[CONFIG]** > **Pickup Location** > set **Pickup Type** = Office.

**Expect:** the **Office** selector appears with a bold note saying you are
responsible for taking every parcel to that office yourself, and that no courier
will collect from your address.

**Then:** set Pickup Type = Address. The Office selector and its note disappear.

### A6. Location sync button · P2

**Path:** **[CONFIG]** > **Location Sync** > Schedule Sync Now.

**Expect:** a confirmation that a sync was scheduled. It does not run
immediately, it queues for the next cron tick (up to a minute).

---

## 4. Checkout: rating

### B1. All three delivery types quote a price · P1

**Path:** storefront, add any product, checkout.

**Expect:** three Pigeon Express methods offered (Address, Office, APS), each with
a price. Prices come from the API, so they vary by destination.

**Fail if:** any method is missing, or shows 0.00, or the flat rate fallback price
appears when Pricing Mode is Dynamic. The log will show the API call and result.

### B2. Repeated quoting does not hammer the API · P2

**Path:** at checkout, change quantity or re-enter the shipping step several times
while watching the log.

**Expect:** the first pass logs API calls; subsequent identical quotes do not.
Prices are cached for 15 minutes keyed on the exact request.

**Fail if:** every single page interaction produces a fresh round of API calls.

### B3. Changing the cart produces a fresh price · P1

**Path:** quote a cart, note the price, then change the quantity substantially and
re-quote.

**Expect:** the price changes. Caching must not survive a change to weight,
dimensions, destination or services.

**Fail if:** the price is stuck at the old value.

---

## 5. Checkout: cash on delivery fee

**This is the customer reported bug. Highest priority section.**

### C1. Fee is 1.5 percent, applied once · P1

**Path:** build a cart with a subtotal of exactly **100.00 EUR**, checkout, choose
any Pigeon Express method, note the shipping cost, then go to payment and select
**Cash on Delivery**.

**Expect:** the shipping cost rises by exactly **1.50**.

| Subtotal | Correct increase | Old buggy values |
|---|---|---|
| 100.00 | 1.50 | 2.50, or about 4.00 |
| 200.00 | 3.00 | 5.00, or about 8.00 |
| 50.00 | 0.75 | 1.25 |

**Fail if:** the increase is 2.50, or roughly 4.00, or the fee has a floor of 0.80.

### C2. Fee does not accumulate when toggling payment method · P1

**Path:** from C1, switch to Bank Transfer, then back to Cash on Delivery, three
or four times.

**Expect:** shipping returns to the base price on Bank Transfer, and back to base
plus 1.50 on COD, every time. The number must not creep upward.

**Fail if:** the fee stacks. That is the double charge returning.

### C3. Tax inclusive shipping matches · P1

**Path:** `Stores > Configuration > Sales > Tax > Price Display Settings` and
`Shopping Cart Display Settings` > set **Display Shipping Amount** = `Including Tax`.
Flush cache. Redo C1.

**Expect:** the tax inclusive shipping figure equals the excl tax figure, and both
include the 1.50. Pigeon Express prices are final, so no tax is added on top.

**Fail if:** the incl tax figure is short by the fee amount.

**Afterwards:** set Display Shipping Amount back to whatever it was.

### C4. Shipping tax class must stay None · P1

**Path:** `Stores > Configuration > Sales > Tax > Tax Classes > Tax Class for Shipping`.

**Expect:** `None`.

**Fail if:** anything else is set. Pigeon Express quotes gross prices, so a
shipping tax class would add tax on top and overcharge the customer. Report this
as a configuration defect, do not "fix" it silently.

### C5. Fee is recorded on the order · P2

**Path:** place a COD order, then `Sales > Orders >` open it.

**Expect:** the developer can confirm the `pigeonexpress_cod_fee` column on the
order holds 1.50 (or the correct value for that subtotal).

---

## 6. Checkout: location selection

### D1. Office picker sets a real city and postcode · P1

**Path:** checkout > choose **Office Delivery** > search and select any office.

**Expect:** the chosen office name and address appear. The order's shipping
address ends up with the office's real city and postcode, for example
`Разград / 7200`.

**Fail if:** city or postcode is blank, or shows a placeholder such as
"PigeonExpress". These values now come from our synced data rather than from the
browser.

### D2. APS locker picker · P1

**Path:** checkout > choose **APS (Parcel Locker)** > select a locker.

**Expect:** same as D1. Sofia lockers should resolve to `София / 1000`.

### D3. Rejected location cannot be forced · P1, security

**Path:** at checkout, open the browser console and run:

```js
fetch('/magento/pigeonexpress/checkout/setlocation', {
  method: 'POST',
  headers: {'Content-Type': 'application/x-www-form-urlencoded'},
  body: 'delivery_type=office&location_id=99999999&location_city=Hacked&location_postcode=00000'
}).then(r => r.json()).then(console.log)
```

**Expect:** `{success: false, message: "Unknown location"}`, a warning in the log,
and the city `Hacked` must **not** appear anywhere on the quote or order.

**Repeat with:** `location_id=1 OR 1=1` and `delivery_type=address`. Both must be
rejected.

**Fail if:** any of these returns success, or the injected city reaches the quote.

### D4. Courier note is bounded · P2

**Path:** checkout > office or locker delivery > paste 500+ characters, including
some HTML such as `<b>x</b><script>alert(1)</script>`, into the delivery
instructions or comment field.

**Expect:** the note is truncated to 255 characters, tags are stripped, and
nothing executes. Check the note on the resulting order.

---

## 7. Checkout: address delivery

### E1. Street resolves in a large city · P1

**Path:** checkout > **Address Delivery** > city `София`, street `Витоша`, plus a
house number.

**Expect:** a price is returned and the log shows a resolved `street_id`.

**Fail if:** the log shows the street was not found, or `additional_info` is sent
instead of `street_id`. Before this release large cities always failed.

### E2. Other large cities · P2

**Repeat E1** with `Пловдив`, `Варна` and `Бургас`, using a well known street in
each. Also try a Latin spelling, for example city `Sofia`, street `Vitosha`.

### E3. Unknown street degrades gracefully · P2

**Path:** as E1 but with a nonsense street name.

**Expect:** checkout still returns a price. The street is sent as free text
instead of an id. It must not error or hide the method.

---

## 8. Order placement and waybill

### F1. Waybill payload shape · P1, money

**Path:** place a COD order using Pigeon Express, then
`Sales > Orders >` open it > **Send to Pigeon Express** (button in the Pigeon
Express block on the order Information tab).

**Expect:** in the log, the `ShipmentSender::send payload` entry contains:

```json
"service_codes": { "cod_amount": <order total> }
```

**Fail if:** `cod_amount` appears at the **top level** of the payload next to
`receiver_name`, or any `sms_notification` key appears. That was the bug where the
courier was never told to collect the money.

**Also expect:** a reference number and tracking number come back and are shown on
the order.

### F2. Parcel matches the quote · P1

**Path:** cart with **quantity 40** of `Chaz Kangeroo Hoodie` (1 kg each), place
and send.

**Expect:** the payload `packages` array contains **two parcels, 30 kg and 10 kg**,
and their dimensions match what was quoted.

**Fail if:** one parcel of 40 kg (the API rejects over 30), or dimensions of
10x10x10, or dimensions that differ from the quote.

### F3. Locker shipment omits impossible services · P1

**Path:** **[CONFIG]** > **Additional Services** > set **ID Verification and Document
Signature** = Yes and **Paper Return Receipt** = Yes. Save, flush cache. Place an **APS locker** order
and send it.

**Expect:** the payload `service_codes` does **not** contain
`ID_verification_and_document_signature` or `paper_return_receipt`. The log
explains each was skipped as not available for locker delivery.

**Then:** place an **Office** order with the same settings. Both codes **must**
appear.

### F4. Inspection tier maps correctly · P2

**Path:** **[CONFIG]** > **Office Delivery** > Recipient Inspection = **Inspection only**.
Save, flush, place and send an Office order.

**Expect:** `service_codes` contains `shipment_review` and **not**
`shipment_test_before_payment`.

**Then:** switch to **Inspection and testing** and repeat. Now expect
`shipment_test_before_payment` and **not** `shipment_review`. Never both.

### F5. Insurance sends the order value · P2

**Path:** **[CONFIG]** > **Additional Services** > **Insurance (Declared Value)**
= Yes. Save, flush. Place an order whose **product subtotal is 349.99** and send it.

**Expect:** `"declared_value": 349.99` in `service_codes`, as a number not `true`,
and matching the product subtotal rather than the grand total. Shipping is not
insured, so it must not be included.

**Then:** set Insurance back to No, place another order, and confirm
`declared_value` is absent entirely.

### F6. Double send is refused · P2

**Path:** on an already sent order, click Send to Pigeon Express again.

**Expect:** an error naming the existing reference number. No second shipment.

---

## 9. Order confirmation email

### G1. Delivery details appear in the email · P1

**Path:** place an order with Office or APS delivery and receive the confirmation
email. Use `Marketing > Communications > Email Templates` only if you need to
inspect templates; do not change them.

**Expect:** below the shipping method, a Pigeon Express block showing the delivery
type, the office or locker name and its address, and the courier comment if one
was entered.

**Fail if:** the details are missing entirely.

### G2. A customised order email is preserved · P1

This is the important half of the email change.

**Path:** `Marketing > Communications > Email Templates > Add New Template` >
load the `New Order` default > add an obvious marker such as
`QA MARKER 12345` near the top > save. Then
`Stores > Configuration > Sales > Sales Emails > Order > New Order Confirmation Template`
> select your new template. Flush cache. Place a Pigeon Express order.

**Expect:** the email shows **both** your `QA MARKER 12345` **and** the Pigeon
Express delivery block.

**Fail if:** the marker is gone. The previous release replaced the whole email with
its own copy, which is exactly what this change fixed.

**Afterwards:** set the template back to Default.

### G3. Non Pigeon Express order email is untouched · P1

**Path:** place an order with a different shipping method (enable Flat Rate if
needed at `Stores > Configuration > Sales > Delivery Methods > Flat Rate`).

**Expect:** no Pigeon Express block anywhere in that email.

---

## 10. Location sync

### H1. Nightly sync completes · P1

**Path:** ask the developer to run the sync manually, or use
**[CONFIG]** > Location Sync > Schedule Sync Now and wait for the next cron tick.

**Expect:** in the log, counts for offices, lockers, cities and additional
services. Roughly 169 offices, 84 lockers, 5,284 cities, 9 services.

**Then verify** the counts held: **[CONFIG]** > **Pickup Location** > Pickup
office dropdown still lists hundreds of offices, and **Additional Services** still
shows the expected toggles.

**Fail if:** counts collapse to a handful, or the office dropdown empties.

### H2. Sync survives a partial failure · P2

Developer assisted. With the API unreachable or returning a fragment, the sync
must **refuse** and leave existing data intact rather than deactivating most
offices. Confirm from the log that it aborted and that the office dropdown is
still fully populated afterwards.

---

## 11. Regression

### I1. Other carriers unaffected · P1

Enable Flat Rate, place an order with it. Quote, totals, order and email must all
behave normally with no Pigeon Express involvement.

### I2. Non COD payment · P1

Place a Pigeon Express order paying by Bank Transfer. No COD fee anywhere, and the
waybill payload has no `cod_amount`.

### I3. Flat rate pricing mode · P2

**[CONFIG]** > **Office Delivery** > Pricing Mode = **Fixed (flat rate override)**, then
set the **Flat Rate Price** that appears. Expect that exact price at checkout with no API call in the log. Set back
to Dynamic afterwards.

### I4. Guest and registered checkout · P1

Run C1 once as a guest and once as a logged in customer.

### I5. Admin order creation · P2

`Sales > Orders > Create New Order` > pick a customer, add a product, choose a
Pigeon Express method and Cash on Delivery.

**Expect:** the method is offered and the COD fee behaves as in C1.

---

## 12. Known expected behaviour, do not report these

| Observation | Why it is correct |
|---|---|
| **There is no separate "Cash on Delivery Fee" row at checkout.** The fee is inside the Shipping figure. | Deliberate. A separate total row would also need handling in invoices and credit memos, and the API gives no way to toggle COD independently. Verify the Shipping figure rises instead. |
| Locker shipments do not request a recipient SMS. | `sms_notification_receiver` is not enabled on this Pigeon Express account, so it is skipped and logged. Ask Pigeon Express to enable it if it is wanted. |
| The Special Packaging toggle is not in the admin at all. | `requires_special_packaging` is not included in this Pigeon Express account, so the toggle is hidden rather than shown as a switch that does nothing. |
| Enabling ID Verification has no effect on locker orders. | A locker has nobody present to check documents. See F3. |
| Office and locker records have an empty `city` column in the database. | The API leaves it empty and fills `city_id`. The readable city comes from a join to the city table. |
| A price does not change for up to 15 minutes if nothing about the cart changed. | Intentional caching. Any change to the cart or destination produces a fresh quote. See B3. |
| Both inspection tiers are never sent together. | They are a hierarchy: testing already includes inspection. |

---

## 13. Reporting a failure

For each failure please capture:

1. The test case ID from this document.
2. The exact steps, including cart contents and subtotal.
3. Cart subtotal, shipping shown, and expected shipping.
4. The matching block from `var/log/pigeonexpress.log`, especially any
   `RateCollector::getRatePrice API result` or `ShipmentSender::send payload`
   entry.
5. Browser console output for anything at checkout.
6. Order increment ID if an order was placed.

Anything involving a wrong amount of money is P1 regardless of where it appears.
