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

## Adding a new shipped directory

Add it to `SHIP_PATHS` in `bin/build-zip.sh`. Nothing else picks it up
automatically, by design.

## Checklist

1. `bin/magento setup:db-declaration:generate-whitelist --module-name=PigeonExpress_Shipping` if `db_schema.xml` changed
2. Bump the version in `composer.json`
3. Update `CHANGELOG.md`
4. `bin/build-zip.sh`
5. Confirm the printed contents list has no surprises
6. Install the zip into a clean Magento and run through checkout with a COD
   payment method, confirming the fee matches what the API returns
