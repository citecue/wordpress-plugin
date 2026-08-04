#!/usr/bin/env bash
#
# Builds the installable WordPress plugin zip.
#
# GitHub's own "Download ZIP" button produces wordpress-plugin-main.zip, which
# unpacks to wordpress-plugin-main/ and carries the tests, CI config and
# Composer files with it. WordPress keys a plugin by its directory name — that
# name ends up in the plugins list, in update checks and in every support
# thread — so the distributed archive has to unpack to citecue/ and contain
# only the files the plugin loads at runtime.
#
# `git archive` gives both properties for free: --prefix sets the directory
# name, and it only ever reads tracked files honouring the export-ignore rules
# in .gitattributes, so an untracked vendor/, .env or editor backup can never
# be swept into a release.
#
# Usage: bin/build-plugin-zip.sh [ref]      (ref defaults to HEAD)

set -euo pipefail

SLUG=citecue
REF=${1:-HEAD}

ROOT=$(git rev-parse --show-toplevel)
OUT_DIR="$ROOT/dist"
OUT="$OUT_DIR/$SLUG.zip"

if ! git rev-parse --verify --quiet "$REF^{commit}" >/dev/null; then
	echo "error: '$REF' is not a commit in this repository" >&2
	exit 1
fi

# Read the version out of the ref being built, not the working tree, so that
# building an old tag reports that tag's version.
show() { git show "$REF:$1"; }

header_version=$(show citecue.php | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' | head -n 1)
const_version=$(show citecue.php | sed -n "s/^[[:space:]]*define([[:space:]]*'CITECUE_VERSION'[[:space:]]*,[[:space:]]*'\([^']*\)'.*/\1/p" | head -n 1)
readme_version=$(show readme.txt | sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' | head -n 1)

if [ -z "$header_version" ]; then
	echo "error: could not read the Version: header from citecue.php" >&2
	exit 1
fi

# A plugin whose three version strings disagree updates incorrectly in the
# wild, and the mismatch is invisible until someone tries to upgrade.
if [ "$header_version" != "$const_version" ] || [ "$header_version" != "$readme_version" ]; then
	echo "error: version mismatch in $REF" >&2
	echo "  citecue.php header:  ${header_version:-<missing>}" >&2
	echo "  CITECUE_VERSION:     ${const_version:-<missing>}" >&2
	echo "  readme.txt Stable tag: ${readme_version:-<missing>}" >&2
	exit 1
fi

if [ "$REF" = HEAD ] && ! git diff --quiet HEAD -- .; then
	echo "warning: the working tree has uncommitted changes; building HEAD as committed" >&2
fi

mkdir -p "$OUT_DIR"
rm -f "$OUT"
git archive --format=zip -9 --prefix="$SLUG/" --output="$OUT" "$REF"

# Guard the two properties the whole exercise is about: one top-level
# directory, correctly named, and no development files inside it.
entries=$(unzip -Z1 "$OUT")
stray_root=$(printf '%s\n' "$entries" | grep -v "^$SLUG/" || true)
if [ -n "$stray_root" ]; then
	echo "error: archive has entries outside $SLUG/:" >&2
	printf '%s\n' "$stray_root" >&2
	exit 1
fi

stray_dev=$(printf '%s\n' "$entries" | grep -E "^$SLUG/(tests/|bin/|docs/|\.github/|composer\.|phpunit|\.phpcs|README\.md)" || true)
if [ -n "$stray_dev" ]; then
	echo "error: development files leaked into the archive:" >&2
	printf '%s\n' "$stray_dev" >&2
	exit 1
fi

for required in "$SLUG/citecue.php" "$SLUG/readme.txt" "$SLUG/uninstall.php"; do
	if ! printf '%s\n' "$entries" | grep -qx "$required"; then
		echo "error: $required is missing from the archive" >&2
		exit 1
	fi
done

echo "Built $OUT (version $header_version, $(printf '%s\n' "$entries" | grep -cv '/$') files)"
printf '%s\n' "$entries" | sed 's/^/  /'
