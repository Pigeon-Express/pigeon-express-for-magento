#!/usr/bin/env bash
#
# Build a distributable zip of PigeonExpress_Shipping.
#
# This script copies an explicit WHITELIST of paths. Anything not named in
# SHIP_PATHS below cannot end up in the archive, so a new notes file, scratch
# script or internal document can never reach a client by accident. Adding a
# new shipping directory to the module means adding it here on purpose.
#
# Usage:
#   bin/build-zip.sh [version]
#
# Version defaults to the value in composer.json.

set -euo pipefail

MODULE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$MODULE_ROOT"

# ---------------------------------------------------------------------------
# The whitelist. Only these paths ship.
# ---------------------------------------------------------------------------
SHIP_PATHS=(
  registration.php
  composer.json
  README.md
  CHANGELOG.md
  LICENSE
  SECURITY.md
  Api
  Block
  Controller
  etc
  Exception
  i18n
  Logger
  Model
  Observer
  Plugin
  Setup
  view
)

# Files that must never ship even if they sit inside a whitelisted directory.
PRUNE_NAMES=(
  ".DS_Store"
  "*.orig"
  "*.rej"
  "*.bak"
  "*~"
)

# ---------------------------------------------------------------------------
# The SDK, vendored in at build time.
#
# It lives in its own repository and is not on Packagist, so a client cannot
# resolve `pigeonexpress/php-sdk` through Composer. Without it the module
# installs and then fatals on the first rate request. Copying it in here keeps
# one source of truth, the SDK repo, while making the archive self contained.
#
# Only src/ and the licence are taken. The SDK's own tests, docs and readme
# would trip the verification below, and a client has no use for them.
# ---------------------------------------------------------------------------
SDK_SRC="$(cd "$MODULE_ROOT/../../../.." && pwd)/packages/pigeonexpress-php-sdk"
SDK_DEST_REL="lib/pigeonexpress-php-sdk"

VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
  VERSION="$(php -r '$c = json_decode(file_get_contents("composer.json"), true); echo $c["version"] ?? "0.0.0";' 2>/dev/null || echo "0.0.0")"
fi

PACKAGE_NAME="pigeon-express-for-magento"
STAGING="$(mktemp -d)"
TARGET_DIR="$STAGING/$PACKAGE_NAME"
OUT_DIR="$MODULE_ROOT/build"
OUT_FILE="$OUT_DIR/${PACKAGE_NAME}.${VERSION}.zip"

cleanup() { rm -rf "$STAGING"; }
trap cleanup EXIT

echo "Building $PACKAGE_NAME $VERSION"
echo

mkdir -p "$TARGET_DIR" "$OUT_DIR"

missing=0
for path in "${SHIP_PATHS[@]}"; do
  if [[ ! -e "$path" ]]; then
    echo "  WARNING: whitelisted path not found, skipping: $path"
    missing=$((missing + 1))
    continue
  fi
  cp -R "$path" "$TARGET_DIR/"
  echo "  added   $path"
done

# The SDK. Load bearing, so a missing one fails the build rather than producing
# an archive that installs and then dies on the first call to the carrier.
if [[ ! -d "$SDK_SRC/src" ]]; then
  echo
  echo "REFUSING TO SHIP: the Pigeon Express SDK was not found at:"
  echo "  $SDK_SRC/src"
  echo "The module cannot talk to the carrier without it."
  exit 1
fi

mkdir -p "$TARGET_DIR/$SDK_DEST_REL"
cp -R "$SDK_SRC/src" "$TARGET_DIR/$SDK_DEST_REL/"
[[ -f "$SDK_SRC/LICENSE" ]] && cp "$SDK_SRC/LICENSE" "$TARGET_DIR/$SDK_DEST_REL/"
SDK_VERSION="$(cd "$SDK_SRC" && git describe --tags --always 2>/dev/null || echo "unknown")"
echo "  added   $SDK_DEST_REL  (SDK $SDK_VERSION, $(find "$TARGET_DIR/$SDK_DEST_REL" -name '*.php' | wc -l | tr -d ' ') files)"

# Strip editor and merge leftovers that may live inside shipped directories.
for pattern in "${PRUNE_NAMES[@]}"; do
  find "$TARGET_DIR" -name "$pattern" -delete 2>/dev/null || true
done

# Belt and braces: nothing version control related may survive.
find "$TARGET_DIR" -name ".git*" -prune -exec rm -rf {} + 2>/dev/null || true

rm -f "$OUT_FILE"
(cd "$STAGING" && zip -rq "$OUT_FILE" "$PACKAGE_NAME")

echo
echo "Wrote $OUT_FILE"
echo

# ---------------------------------------------------------------------------
# Verification. Fail loudly if anything internal slipped through.
# ---------------------------------------------------------------------------
FORBIDDEN_PATTERN='(^|/)(\.ai|docs|bin|Test|tests|\.github|node_modules)/|(^|/)\.git|(^|/)\.claude|CLAUDE\.md|phpunit|phpstan|\.zip$'
LEAKED="$(unzip -Z1 "$OUT_FILE" | sed "s|^$PACKAGE_NAME/||" | grep -nE "$FORBIDDEN_PATTERN" || true)"

if [[ -n "$LEAKED" ]]; then
  echo "REFUSING TO SHIP: internal files found in the archive:"
  echo "$LEAKED"
  rm -f "$OUT_FILE"
  exit 1
fi

# Only these markdown files are allowed in a client archive.
STRAY_MD="$(unzip -Z1 "$OUT_FILE" | sed "s|^$PACKAGE_NAME/||" | grep -E '\.md$' | grep -vE '^(README|SECURITY|CHANGELOG)\.md$' || true)"
if [[ -n "$STRAY_MD" ]]; then
  echo "REFUSING TO SHIP: unexpected markdown files in the archive:"
  echo "$STRAY_MD"
  rm -f "$OUT_FILE"
  exit 1
fi

# The archive must be able to reach the carrier. Checking the entry class is
# present catches a vendoring step that silently copied nothing.
for required in "$SDK_DEST_REL/src/PigeonExpress.php" "$SDK_DEST_REL/src/Configuration.php"; do
  if ! unzip -Z1 "$OUT_FILE" | sed "s|^$PACKAGE_NAME/||" | grep -qx "$required"; then
    echo "REFUSING TO SHIP: the archive has no $required, so it cannot call the API."
    rm -f "$OUT_FILE"
    exit 1
  fi
done

echo "Verified clean. Contents:"
unzip -Z1 "$OUT_FILE" | sed "s|^$PACKAGE_NAME/||" | awk -F/ 'NF>1 {print $1"/"} NF==1 && $1!="" {print $1}' | sort -u | sed 's/^/  /'
echo
echo "Files: $(unzip -Z1 "$OUT_FILE" | grep -cv '/$')"
[[ "$missing" -gt 0 ]] && echo "Note: $missing whitelisted path(s) were missing."
exit 0
