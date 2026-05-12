#!/usr/bin/env bash
#
# Subset Material Symbols Outlined to only the icons used in the project.
#
# Reads icon names from:
#   1. config/fonts.php (canonical list)
#   2. Static scan of templates (catches additions not yet in config)
#
# Produces:
#   public/fonts/material-symbols-subset.woff2
#
# Prerequisites:
#   build-tools/.venv with fonttools + brotli installed
#   build-tools/MaterialSymbolsOutlined.woff2 (source font)
#   build-tools/material-symbols.codepoints (icon name → codepoint mapping)
#
# Usage:
#   bash build-tools/subset-icons.sh
#
# Add to CI or post-deploy hook to detect new icons not in subset.
#

set -euo pipefail
cd "$(git rev-parse --show-toplevel 2>/dev/null || echo .)"

BUILD_TOOLS="build-tools"
SOURCE_FONT="$BUILD_TOOLS/MaterialSymbolsOutlined.woff2"
CODEPOINTS="$BUILD_TOOLS/material-symbols.codepoints"
OUTPUT_DIR="public/fonts"
OUTPUT_FONT="$OUTPUT_DIR/material-symbols-subset.woff2"
VENV="$BUILD_TOOLS/.venv"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m'

echo "=== Material Symbols Subsetting ==="

# ── Validate prerequisites ──────────────────────────────────────────────────
if [[ ! -f "$SOURCE_FONT" ]]; then
    echo -e "${RED}Error:${NC} Source font not found: $SOURCE_FONT"
    echo "Download it from:"
    echo "  curl -sL 'https://github.com/google/material-design-icons/raw/master/variablefont/MaterialSymbolsOutlined%5BFILL%2CGRAD%2Copsz%2Cwght%5D.woff2' -o $SOURCE_FONT"
    exit 1
fi

if [[ ! -f "$CODEPOINTS" ]]; then
    echo -e "${RED}Error:${NC} Codepoints mapping not found: $CODEPOINTS"
    echo "Download it from:"
    echo "  curl -sL 'https://raw.githubusercontent.com/google/material-design-icons/master/variablefont/MaterialSymbolsOutlined%5BFILL%2CGRAD%2Copsz%2Cwght%5D.codepoints' -o $CODEPOINTS"
    exit 1
fi

if [[ ! -f "$VENV/bin/pyftsubset" ]]; then
    echo -e "${RED}Error:${NC} pyftsubset not found. Set up the venv:"
    echo "  python3 -m venv $VENV && source $VENV/bin/activate && pip install fonttools brotli"
    exit 1
fi

mkdir -p "$OUTPUT_DIR"

# ── Collect icon names ──────────────────────────────────────────────────────
# 1. From config/fonts.php (canonical list maintained by developers)
CONFIG_ICONS=$(php -r "
    \$icons = include 'config/fonts.php';
    echo implode(chr(10), \$icons['material_symbols'] ?? []);
" 2>/dev/null || echo "")

# 2. From static scan of Blade templates, PHP enums, and JS files
#    Catches icons added but not yet registered in config
SCAN_ICONS=$(
    # Static icon names in Blade templates (between > and </span>)
    rg -o 'material-symbols-outlined[^>]*>\K[a-z_0-9]+' resources/views/ --no-filename 2>/dev/null || true

    # Icon names returned by PHP enum icon() methods
    rg -o "return '([a-z_0-9]+)'" app/Enums/ --no-filename -r '$1' 2>/dev/null || true

    # Icon names in PHP arrays (dashboard, navigation, etc.)
    rg -o "'icon'\s*=>\s*'([a-z_0-9]+)'" app/ -r '$1' --no-filename 2>/dev/null || true

    # Icon names in JS files
    rg -o "material-symbols-outlined[^>]*>\K[a-z_0-9]+" resources/js/ --no-filename 2>/dev/null || true
)

# Combine and deduplicate
ALL_ICONS=$(echo -e "${CONFIG_ICONS}\n${SCAN_ICONS}" | sort -u | sed '/^$/d')
ICON_COUNT=$(echo "$ALL_ICONS" | wc -l | tr -d ' ')
echo "Found $ICON_COUNT unique icon names"

# ── Map icon names to codepoints ────────────────────────────────────────────
UNICODES=""
MISSING=""
while IFS= read -r icon_name; do
    [[ -z "$icon_name" ]] && continue
    codepoint=$(awk -v name="$icon_name" '$1 == name { print "U+"toupper($2); exit }' "$CODEPOINTS")
    if [[ -n "$codepoint" ]]; then
        UNICODES="$UNICODES,$codepoint"
    else
        MISSING="$MISSING $icon_name"
    fi
done <<< "$ALL_ICONS"

UNICODES="${UNICODES#,}"  # Remove leading comma

if [[ -n "$MISSING" ]]; then
    echo -e "${YELLOW}Warning:${NC} Icons not found in codepoint mapping (may be renamed or removed):$MISSING"
fi

# Always include .notdef, .null (required glyphs) and space
UNICODES="U+0000-0020,$UNICODES"

GLYPH_COUNT=$(echo "$UNICODES" | tr ',' '\n' | wc -l | tr -d ' ')
echo "Subsetting to $GLYPH_COUNT glyphs..."

# ── Run subsetting ──────────────────────────────────────────────────────────
source "$VENV/bin/activate"

pyftsubset "$SOURCE_FONT" \
    --unicodes="$UNICODES" \
    --layout-features='*' \
    --name-IDs='*' \
    --flavor=woff2 \
    --output-file="$OUTPUT_FONT" \
    --verbose 2>&1 | tail -3

# ── Report results ──────────────────────────────────────────────────────────
ORIG_SIZE=$(wc -c < "$SOURCE_FONT" | tr -d ' ')
NEW_SIZE=$(wc -c < "$OUTPUT_FONT" | tr -d ' ')
SAVED=$(( ORIG_SIZE - NEW_SIZE ))
PCT=$(( SAVED * 100 / ORIG_SIZE ))

echo ""
echo -e "${GREEN}Done!${NC}"
echo "  Original: ${ORIG_SIZE} bytes ($(( ORIG_SIZE / 1024 )) KB)"
echo "  Subset:   ${NEW_SIZE} bytes ($(( NEW_SIZE / 1024 )) KB)"
echo "  Saved:    ${SAVED} bytes (${PCT}% reduction)"
echo "  Output:   $OUTPUT_FONT"

# ── Audit: check for icons used in code but not in config ───────────────────
if [[ -n "$CONFIG_ICONS" ]]; then
    ONLY_IN_SCAN=$(comm -23 <(echo "$ALL_ICONS" | sort) <(echo "$CONFIG_ICONS" | sort) | sed '/^$/d')
    if [[ -n "$ONLY_IN_SCAN" ]]; then
        echo ""
        echo -e "${YELLOW}Icons found in code but not in config/fonts.php:${NC}"
        echo "$ONLY_IN_SCAN" | while read -r icon; do
            echo "  - $icon"
        done
        echo "Consider adding these to config/fonts.php for documentation."
    fi
fi
