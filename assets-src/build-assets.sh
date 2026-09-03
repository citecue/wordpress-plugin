#!/usr/bin/env bash
#
# Regenerates the WordPress.org directory assets in .wordpress-org/.
#
# These are the banner and icon the plugin directory shows above the readme.
# They are NOT part of the plugin: WordPress.org serves them from the `assets/`
# folder of the SVN repository, which sits beside `trunk/` and `tags/` and is
# never installed on a site. Both directories are export-ignored so they can
# never reach the distributed zip.
#
# Rendering is done by headless Chrome rather than a dedicated rasterizer
# because it is the one thing reliably present on a Mac that can lay out real
# webfont text, and --window-size with --force-device-scale-factor gives exact
# pixel dimensions. The 2x pass is a genuine re-render, not an upscale.
#
# Fonts come from the citecue2 site repository rather than being vendored
# here: they are the same faces the brand uses everywhere, and a second copy
# in a second repo is a second thing to update when they change.
#
# Usage: assets-src/build-assets.sh
#   CITECUE_FONT_DIR   override the font source directory
#   CHROME             override the Chrome binary

set -euo pipefail

ROOT=$(git rev-parse --show-toplevel)
SRC="$ROOT/assets-src"
OUT="$ROOT/.wordpress-org"

FONT_DIR=${CITECUE_FONT_DIR:-"$HOME/Sites/citecue2/public/fonts"}
CHROME=${CHROME:-"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"}

if [ ! -x "$CHROME" ]; then
	echo "error: Chrome not found at $CHROME (set CHROME=)" >&2
	exit 1
fi

for face in schibsted-grotesk schibsted-grotesk-italic instrument-sans jetbrains-mono; do
	if [ ! -f "$FONT_DIR/$face.woff2" ]; then
		echo "error: $FONT_DIR/$face.woff2 not found (set CITECUE_FONT_DIR=)" >&2
		exit 1
	fi
done

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

# Inline the faces. A relative @font-face would be a separate fetch that
# --screenshot does not wait for, and the banner would silently render in a
# fallback with different metrics.
python3 - "$SRC/banner.html" "$FONT_DIR" "$WORK/banner.html" <<'PY'
import base64, pathlib, sys

template, font_dir, out = (pathlib.Path(p) for p in sys.argv[1:4])
html = template.read_text()

for token, face in (
    ("__SG__", "schibsted-grotesk"),
    ("__SGI__", "schibsted-grotesk-italic"),
    ("__IS__", "instrument-sans"),
    ("__JB__", "jetbrains-mono"),
):
    if token not in html:
        sys.exit(f"error: {token} missing from {template}")
    html = html.replace(token, base64.b64encode((font_dir / f"{face}.woff2").read_bytes()).decode())

out.write_text(html)
PY

shot() { # shot <output> <scale>
	"$CHROME" --headless --disable-gpu --no-sandbox --hide-scrollbars \
		--force-device-scale-factor="$2" --window-size=772,250 \
		--screenshot="$1" "file://$WORK/banner.html" >/dev/null 2>&1
}

mkdir -p "$OUT"
shot "$OUT/banner-772x250.png" 1
shot "$OUT/banner-1544x500.png" 2

# The icon is already vector; Chrome just rasterizes it at the two sizes the
# directory asks for.
cat > "$WORK/icon.html" <<HTML
<meta charset="utf-8">
<style>*{margin:0;padding:0}html,body{width:256px;height:256px;overflow:hidden}
img{width:256px;height:256px;display:block}</style>
<img src="file://$OUT/icon.svg">
HTML
"$CHROME" --headless --disable-gpu --no-sandbox --hide-scrollbars \
	--force-device-scale-factor=1 --window-size=256,256 \
	--screenshot="$OUT/icon-256x256.png" "file://$WORK/icon.html" >/dev/null 2>&1
sips -Z 128 --out "$OUT/icon-128x128.png" "$OUT/icon-256x256.png" >/dev/null

# A banner at the wrong size is rejected by the directory, and the failure is
# a silently missing header rather than an error, so the sizes are asserted.
check() { # check <file> <w> <h>
	local w h
	w=$(sips -g pixelWidth "$1" | awk '/pixelWidth/ {print $2}')
	h=$(sips -g pixelHeight "$1" | awk '/pixelHeight/ {print $2}')
	if [ "$w" != "$2" ] || [ "$h" != "$3" ]; then
		echo "error: $(basename "$1") is ${w}x${h}, expected $2x$3" >&2
		exit 1
	fi
	echo "  $(basename "$1")  ${w}x${h}"
}

echo "Built $OUT"
check "$OUT/banner-772x250.png" 772 250
check "$OUT/banner-1544x500.png" 1544 500
check "$OUT/icon-256x256.png" 256 256
check "$OUT/icon-128x128.png" 128 128
