#!/usr/bin/env bash
# Holt das Marken-Logo aus Prismic, beschneidet es auf den Inhalt und rastert es
# fuer die PDF-Ausgabe. mPDF kann die Original-SVG (247 KB, Clip-Paths, Glyph-Pfade)
# nicht zuverlaessig rendern, deshalb PNG.
set -euo pipefail

cd "$(dirname "$0")/.."
OUT=public/assets

SRC_URL='https://premium-monteurzimmer.cdn.prismic.io/premium-monteurzimmer/mZmtuL7uBlbrw0YP_BlackandWhiteModernClothingStoreLogo-Bannerfu%CC%88rdrau%C3%9Fen-60x36Zoll---Banner-Querformat---Bannerfu%CC%88rdrau%C3%9Fen-72x48Zoll---Banner--Pra%CC%88sentation-.svg'

# Inhaltsbereich innerhalb der 1024.5x576 viewBox, per Augenmass ermittelt.
CROP_VIEWBOX='225 52 570 430'

CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
[ -x "$CHROME" ] || CHROME="/Applications/Chromium.app/Contents/MacOS/Chromium"
[ -x "$CHROME" ] || { echo "Chrome/Chromium nicht gefunden" >&2; exit 1; }

mkdir -p "$OUT"
curl -sSfL -o "$OUT/logo.svg" "$SRC_URL"

# Nur das Wurzel-<svg> ersetzen: width/height der Quelle (1366x768) haengen nicht am
# viewBox und wuerden einen reinen viewBox-Tausch wirkungslos machen.
/usr/bin/perl -0pe "s#<svg\\b[^>]*>#<svg xmlns=\"http://www.w3.org/2000/svg\" xmlns:xlink=\"http://www.w3.org/1999/xlink\" width=\"800\" height=\"604\" viewBox=\"$CROP_VIEWBOX\">#" \
  "$OUT/logo.svg" > /tmp/logo-cropped.svg

"$CHROME" --headless --disable-gpu \
  --screenshot=/tmp/logo-raster.png \
  --window-size=800,604 \
  --force-device-scale-factor=2 \
  --default-background-color=00000000 \
  --hide-scrollbars \
  "file:///tmp/logo-cropped.svg" 2>/dev/null

cp /tmp/logo-raster.png "$OUT/logo.png"
/usr/bin/sips -g pixelWidth -g pixelHeight "$OUT/logo.png"
