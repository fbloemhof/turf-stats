#!/usr/bin/env bash
#
# Builds a distributable plugin zip with the required
# turf-stats/turf-stats.php folder structure, for local testing or a
# manual install. The canonical release path is the "Deploy to
# WordPress.org" GitHub Action (.github/workflows/deploy.yml), which
# commits a tag straight to the wordpress.org SVN repo using .distignore
# to exclude the same dev-only files this script excludes.
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(grep -oP "define\( 'TURF_VERSION', '\K[^']+" "$PLUGIN_DIR/turf-stats.php")"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/turf-stats"
rsync -a \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='.gitignore' \
	--exclude='.gitattributes' \
	--exclude='.distignore' \
	--exclude='.wordpress-org' \
	--exclude='scripts' \
	--exclude='local' \
	--exclude='*.zip' \
	"$PLUGIN_DIR/" "$STAGE/turf-stats/"

OUT="$PLUGIN_DIR/turf-stats-$VERSION.zip"
rm -f "$OUT"
( cd "$STAGE" && zip -qr "$OUT" turf-stats )

echo "built: $OUT"
unzip -l "$OUT" | tail -1
