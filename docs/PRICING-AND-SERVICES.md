# Pricing and additional services

The one rule: **no price, fee, percentage or minimum charge may be written into
this module.** Every monetary figure comes from the Pigeon Express API. This
document explains where each number comes from and how to add a new service
without reintroducing a hardcoded rate.

This rule exists because it was broken. The module shipped a
`FEE_PERCENT = 0.025` constant for the cash on delivery fee. Pigeon Express
later moved the real rate to 1.5 percent and every store running the module kept
charging 2.5 percent, on top of a second COD fee the API was already including
in the shipping price.

## What the API does and does not tell us

`GET /v1/additional-services` returns the catalogue: `code`, `name`,
`description`, `input_type`, `option_group`. **It returns no prices.** Do not go
looking for a price field there, it does not exist.

`POST /v1/shipments/calculate` is the only authoritative source of money. Given
a payload it returns:

| Field | Meaning |
|-------|---------|
| `shipping_price` | Base carriage, before additional services |
| `total_service_fees` | Sum of every additional service fee applied |
| `total_price` | `shipping_price` plus `total_service_fees` |
| `service_codes` | Flat list of the service codes the API actually charged for |

There is no per service breakdown. `total_service_fees` is a single number. To
learn the price of one service you price the same shipment twice, once without
it and once with it, and take the difference. That is exactly what the module
does for COD.

A worked example against the demo API, pickup office 149 to office 90, 1.5 kg:

```
without services      shipping 2.21   service fees 0.38   total 2.59
service_codes.cod_amount = 100.00
                      shipping 2.21   service fees 1.88   total 4.09
difference                                       1.50   = 1.5% of 100
```

Note the 0.38 baseline. `total_service_fees` is **not** the COD fee, it includes
fees that apply regardless. Always take the difference, never the raw value.

## How a fee reaches a customer

```
RateCollector::getRatePrice()
  |
  |-- builds a COD free payload, prices it            -> the shipping rate
  |
  `-- resolveCodFee(): prices the same payload again
      with service_codes.cod_amount, subtracts        -> the real COD fee
        |
        `-- CodFeeResolver::rememberFee()
              |-- runtime + Magento cache, 15 min
              `-- pigeonexpress_additional_service.last_fee_amount / _basis

CodFee (quote total collector, sort order 450)
  `-- CodFeeResolver::resolve()
        1. value cached during this request's rate collection
        2. else last_fee_amount / last_fee_basis ratio applied to this cart
        3. else 0.00 and a warning in the log
```

Step 3 is deliberate. If no API derived figure has ever been obtained, the
customer is charged nothing. Charging a guessed fee is worse than charging none.

**The shipping rate must stay free of COD.** `buildServiceCodes()` deliberately
omits `cod_amount`, because the total collector adds the fee. Putting it back
would charge the customer twice, which is the bug this design replaced.

## Tax

**Pigeon Express prices are final, gross values.** The shipping price and every
additional service fee are what the customer pays. VAT applies to the order
contents, not to carriage.

So the module adds the COD fee at face value and does not tax it. The quote total
collector runs after the tax pass and writes the fee into both the net and the
tax inclusive shipping figures, keeping them equal in respect of the fee.

This lines up with Magento's default `tax/classes/shipping_tax_class = 0`, which
means no tax on shipping. **If someone sets a real shipping tax class in admin,
Magento will add tax on top of the API price and the customer will be
overcharged.** That setting must stay at None for this carrier. If Pigeon Express
ever starts quoting net prices, this decision reverses and the collector has to
move ahead of the tax pass.

## Storage and refresh

`pigeonexpress_additional_service` mirrors the API catalogue.

| Column | Source |
|--------|--------|
| `code`, `name`, `description`, `input_type`, `option_group` | `GET /additional-services` |
| `is_active` | Present in the last successful sync |
| `last_fee_amount`, `last_fee_basis` | Written only after the API charged that amount |
| `fee_synced_at` | When the fee was last resolved from the API |

The catalogue refreshes nightly through `LocationSync::syncAll()` in the
`pigeonexpress` cron group, next to offices, lockers and cities. Services that
vanish from the API are flagged inactive rather than deleted, so their fee
history survives. A catalogue failure is caught and logged, it never discards a
successful location sync.

To resync by hand:

```bash
php8.3 -d disable_functions= -d memory_limit=-1 bin/magento cron:run --group=pigeonexpress
```

## Adding a new additional service

1. Confirm the code exists in `GET /v1/additional-services`. `RateCollector::isServiceOffered()`
   drops codes the API does not list, and logs when it does. `requires_special_packaging`
   is currently mapped in the module but absent from the demo catalogue.
2. Add the admin toggle in `etc/adminhtml/system.xml` as `service_<your_code>`.
3. Map the config code to the API code in `RateCollector::buildServiceCodes()`.
4. **Do not add a price field.** If the service costs money, the API will include
   it in `total_service_fees` and the rate will rise on its own.
5. If the service needs to be shown as a separate line rather than folded into
   the shipping price, follow the COD pattern: price twice, take the difference,
   store it through `AdditionalServiceRepository::recordFee()`.

## Free shipping

Two independent things make a delivery free, and either is enough:

1. A **cart price rule** with Magento's Free Shipping action. It reaches the
   carrier as `RateRequest::getFreeShipping()`. Magento only sets it once the
   whole cart qualifies, so a rule covering part of a cart does nothing here: one
   parcel cannot be half free.
2. A **per delivery type threshold** in the carrier config. Compared against
   `getPackageValueWithDiscount()`, which is base currency, after discounts, and
   tax inclusive only where the store's prices already include tax. The same
   figure DHL, UPS and FedEx compare. Inclusive, so a cart of exactly the
   advertised amount qualifies.

`Model/Rate/FreeShipping.php` is the only place either is evaluated during rate
collection. Nothing downstream asks the question again, because the collectors
that write the values it would have to read run after the rates and do not hold
the same figures at the two moments.

What a free delivery changes:

| | Free | Not free |
|---|---|---|
| Rate | `0.00`, and no `calculate` call | quoted or flat |
| Payload validation | still runs | runs |
| COD fee | waived | charged |
| `who_pays` fallback | `sender` | store setting |

The payload is still built for a free type even though no price is asked for,
because building it is what rejects a parcel the carrier will not take. Skipping
it would offer a free locker delivery for something that does not fit in a
locker. A type that throws is still hidden, free or not: not knowing whether the
carrier serves a destination is a reason to withhold the option, not to give it
away.

The COD waiver reads `$total->getBaseShippingAmount() <= 0`, not the threshold.
That covers a threshold, a cart rule and a deliberately zero flat rate without
having to tell them apart, and it cannot drift from what the customer is actually
being asked to pay.

The `who_pays` override cannot use the same test. `who_pays=receiver` with zero
rates is a supported setup, documented in `etc/config.xml`, where the courier
collects everything at the door. Flipping those stores to sender pays would
silently start billing the merchant for every waybill. So the reason a delivery
cost nothing is recorded on the order address when the order is placed, in
`pigeonexpress_order_address.pigeonexpress_free_shipping`, and read back at
shipment time.

## Things that will reintroduce the bug

- Any literal percentage, minimum or currency amount in PHP, XML, JS or a template.
- Reading `total_service_fees` as if it were one service's price.
- Adding `cod_amount` back into the rate payload while the total collector is active.
- Computing a fee in JavaScript. The old summary component did this and silently
  dropped the minimum charge, so the browser and the server disagreed.
- A default value on an admin fee field. There are no admin fee fields, on purpose.
