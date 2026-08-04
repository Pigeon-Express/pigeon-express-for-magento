# Building a release

## Build the client zip

```bash
bin/build-zip.sh 1.0.1
```

Writes `build/pigeon-express-for-magento.1.0.1.zip`. With no argument the
version comes from `composer.json`.

## Why it cannot leak

The script copies an explicit **whitelist**, `SHIP_PATHS` at the top of
`bin/build-zip.sh`. Anything not named there is never copied, so a new notes
file, scratch script or internal document cannot reach a client even if nobody
remembers to exclude it. This is the whole point: excluding by pattern fails
open, including by name fails closed.

Currently shipped:

```
registration.php  composer.json  README.md  LICENSE  SECURITY.md
Api/  Block/  Controller/  etc/  Exception/  i18n/  Logger/
Model/  Observer/  Plugin/  Setup/  view/
```

Never shipped, and not in the whitelist:

```
.ai/     internal analysis, API spec, working notes
docs/    developer documentation, including this file
bin/     build tooling
build/   output
tests/   when they exist
.git*    version control
```

After zipping, the script verifies the archive and **deletes it and exits
non zero** if anything internal slipped through, or if any markdown other than
`README.md`, `SECURITY.md` or `CHANGELOG.md` is present.

To confirm the guard works, drop a file into `.ai/` or `docs/` and rebuild. It
will not appear in the listing.

`.gitattributes` carries matching `export-ignore` rules so `git archive` and
Composer dist tarballs are clean too. If you add a directory to one, add it to
the other.

## The two changelogs

`CHANGELOG.md` at the module root **ships**. Keep it short and written for the
merchant: what the module does, what changed, what they have to do about it.

`docs/CHANGELOG-DEV.md` is the full engineering record, including what each
defect did before it was fixed. It is in `docs/`, so the whitelist cannot ship
it. Write there first, then summarise upward.

## Adding a new shipped directory

Add it to `SHIP_PATHS` in `bin/build-zip.sh`. Nothing else picks it up
automatically, by design.

## Upgrade notes to hand to the merchant

Magento generates interceptors and extension attribute classes into
`generated/`, and **regenerates them only when the file is absent**. A cache
flush does not touch them. This module's upgrades routinely change constructor
signatures and `etc/extension_attributes.xml`, so an install that keeps its old
generated code fails in ways that look nothing like a stale cache:

```
Call to undefined method
  Magento\Sales\Api\Data\OrderAddressExtension::setPigeonexpressFreeShipping()
Too few arguments to function ...\Interceptor::__construct()
```

Both surface at order placement or on the order screen, not at install time, so
tell them before they hit it. The upgrade is:

```bash
bin/magento module:disable PigeonExpress_Shipping   # if replacing an install
# unzip over app/code/PigeonExpress/Shipping
bin/magento module:enable PigeonExpress_Shipping
bin/magento setup:upgrade
rm -rf generated/code/*          # not optional, and not covered by cache:flush
bin/magento cache:flush
bin/magento setup:di:compile     # production mode only
bin/magento setup:static-content:deploy -f   # production mode only
```

Clearing all of `generated/code` matters rather than just the module's own
namespace: extension attribute classes live under
`generated/code/Magento/Sales/Api/Data/`.

Frontend assets need the static version to change, not just the files to be
replaced, or browsers keep serving the old Knockout templates from cache. In
production `setup:static-content:deploy` does this. In developer mode write the
timestamp with `printf`, never `echo`, which appends a newline that Magento
splices into every asset URL and breaks the JSON in `data-mage-init`:

```bash
printf '%s' "$(date +%s)" > pub/static/deployed_version.txt
```

## Checklist

1. `bin/magento setup:db-declaration:generate-whitelist --module-name=PigeonExpress_Shipping` if `db_schema.xml` changed
2. Bump the version in `composer.json`
3. Update `docs/CHANGELOG-DEV.md`, then summarise it into `CHANGELOG.md`
4. `vendor/bin/phpunit -c app/code/PigeonExpress/Shipping/phpunit.xml.dist`
5. `vendor/bin/phpstan analyse -c app/code/PigeonExpress/Shipping/phpstan.neon`
6. `bin/build-zip.sh`
7. Confirm the printed contents list has no surprises, and that no `docs/`,
   `.ai/` or `bin/` entry appears
8. Install the zip into a clean Magento, following the upgrade notes above, and
   run through checkout with a COD payment method, confirming the fee matches
   what the API returns
