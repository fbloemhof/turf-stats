#!/usr/bin/env bash
#
# Builds the distributable plugin zip(s) with the required
# turf-stats/turf-stats.php folder structure.
#
#   scripts/build-zip.sh          -> turf-stats-<version>.zip        (GitHub release build)
#   scripts/build-zip.sh --wporg  -> turf-stats-<version>-wporg.zip  (WordPress.org build)
#
# The WordPress.org build additionally strips the GitHub update checker
# (includes/updater.php + vendor/plugin-update-checker/): wp.org-hosted
# plugins must not self-update from a non-wordpress.org server (plugin
# directory guideline 8) - wp.org serves those updates itself. The main
# plugin file only loads the updater when the file exists, so the stripped
# build needs no code changes.
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(grep -oP "define\( 'TURF_VERSION', '\K[^']+" "$PLUGIN_DIR/turf-stats.php")"

WPORG=0
if [ "${1:-}" = "--wporg" ]; then
	WPORG=1
fi

SUFFIX=""
[ "$WPORG" -eq 1 ] && SUFFIX="-wporg"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/turf-stats"
rsync -a \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='.gitignore' \
	--exclude='.gitattributes' \
	--exclude='scripts' \
	--exclude='local' \
	--exclude='*.zip' \
	"$PLUGIN_DIR/" "$STAGE/turf-stats/"

if [ "$WPORG" -eq 1 ]; then
	rm -f  "$STAGE/turf-stats/includes/updater.php"
	rm -rf "$STAGE/turf-stats/vendor/plugin-update-checker"
	rmdir  "$STAGE/turf-stats/vendor" 2>/dev/null || true
fi

OUT="$PLUGIN_DIR/turf-stats-$VERSION$SUFFIX.zip"
rm -f "$OUT"
( cd "$STAGE" && zip -qr "$OUT" turf-stats )

echo "built: $OUT"
unzip -l "$OUT" | tail -1
