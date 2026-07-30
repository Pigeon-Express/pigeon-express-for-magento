# Testing

## Running the tests

Module unit tests, from `magento_app/`:

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist --no-coverage \
    app/code/PigeonExpress/Shipping/Test/Unit
```

Or from the module directory, using its own config:

```bash
../../../../vendor/bin/phpunit -c phpunit.xml.dist --no-coverage
```

SDK tests, from `packages/pigeonexpress-php-sdk/`:

```bash
../../vendor/bin/phpunit -c phpunit.xml.dist --no-coverage
```

The SDK suite has its own tiny PSR-4 bootstrap in `tests/bootstrap.php`, so it
runs without a separate `composer install`.

Current state: 61 module tests, 20 SDK tests. Both suites run in well under a
second because nothing in them touches the database, the filesystem or the API.

## What is covered and why

The suite exists because six P1 defects shipped, and every one was a silently
wrong number that no human would notice at a glance. Coverage is aimed at
exactly those failure modes rather than at a line percentage.

| Test | Defect it prevents |
|------|--------------------|
| `Test/Unit/Model/Package/PackageBuilderTest.php` | Rating and the waybill disagreeing on weight, dimensions or parcel count. Asserts dimensions are summed rather than taken from the first product, the 30 kg split, the 1 to 200 cm clamp, and that missing dimensions with no configured defaults are refused rather than guessed |
| `Test/Unit/Model/Service/CodFeeResolverTest.php` | A guessed COD fee reaching a customer. Asserts the cache path, the stored API ratio fallback, and that an unknown fee resolves to zero with a warning instead of an invented number |
| `Test/Unit/Model/Total/Quote/CodFeeTest.php` | The fee being charged twice, or `shipping_incl_tax` drifting from `shipping_amount`. Also asserts the carrier and payment method guards |
| `Test/Unit/Model/ShipmentSenderTest.php` | `cod_amount` leaving the top level again, the undocumented `sms_notification` key coming back, and `service_codes` being built as a list instead of an object |
| `Test/Unit/Model/Carrier/PigeonExpressTest.php` | `getAllowedMethods()` returning a comma joined string instead of a code to title map |
| SDK `tests/DTO/Response/ShipmentCalculationTest.php` | `total_service_fees` being dropped, which is the root cause of the whole fee bug. Also asserts the baseline fee is not mistaken for the COD fee |
| SDK `tests/DTO/Response/AdditionalServiceTest.php` | The catalogue growing a price field unnoticed, and `option_group` exclusivity being ignored |

## Conventions

- Unit tests only, for now. No database, no HTTP, no Magento bootstrap beyond
  `Magento\Framework\TestFramework\Unit\Helper\ObjectManager`.
- Test names read as sentences describing the behaviour, not the method under
  test. `testFeeIsAddedExactlyOnceToTheShippingTotal`, not `testCollect`.
- Where a test guards a specific past defect, say so in a comment. A future
  reader deleting an assertion should know what it was protecting.
- Magic `DataObject` accessors such as `Quote::getSubtotal()` are not real
  methods, so they need `->addMethods([...])` on the mock builder rather than
  plain `createMock`. This bites every time.
- `ObjectManager::getObject()` auto mocks constructor arguments you do not pass,
  which is what makes heavy classes like the carrier testable at all.

## Tests never ship

`Test/` and `phpunit.xml.dist` are absent from the whitelist in
`bin/build-zip.sh`, so they cannot enter a client archive, and the build verifier
fails loudly if they somehow appear. `.gitattributes` carries matching
`export-ignore` entries.

## Gaps worth filling next

- `RateCollector::buildCalculatePayload()` has no coverage. It is the single most
  important method in the module and it is private, large, and reaches into the
  checkout session. Extracting the payload assembly would make it testable.
- No coverage of the checkout location chain, the eleven plugins that carry a
  chosen office from checkout JS through to the order address. That needs
  integration tests, not unit tests.
- No integration test that a COD order ends up with the right total. That is the
  one test that would have caught the original bug end to end.
- No static analysis. PHPStan at level 5 or Magento's own coding standard would
  both find real problems in the older files.
- Nothing runs automatically. These suites are fast and deterministic, so a
  pre-push hook or a CI job is cheap and worth adding.
