#!/usr/bin/env bash
#
# Subset Material Symbols Outlined to only the icons used in the project.
#
# Uses a two-pass approach:
#   Pass 1: Text + unicode subset to preserve GSUB ligature tables
#           (Material Symbols uses ligatures: "menu" → icon glyph)
#   Pass 2: Unicode-only subset to prune unused glyphs pulled by layout closure
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
SUBSET_SCRIPT="$BUILD_TOOLS/_subset_material.py"

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

if [[ ! -f "$VENV/bin/python3" ]]; then
    echo -e "${RED}Error:${NC} Python venv not found. Set up the venv:"
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
SCAN_ICONS=$(
    rg -o 'material-symbols-outlined[^>]*>([a-z_0-9]+)<' resources/views/ --no-filename -r '$1' 2>/dev/null || true
    rg -o "return '([a-z_0-9]+)'" app/Enums/ --no-filename -r '$1' 2>/dev/null || true
    rg -o "'icon'\s*=>\s*'([a-z_0-9]+)'" app/ -r '$1' --no-filename 2>/dev/null || true
    rg -o 'material-symbols-outlined[^>]*>([a-z_0-9]+)<' resources/js/ --no-filename -r '$1' 2>/dev/null || true
)

# Combine and deduplicate
ALL_ICONS=$(echo -e "${CONFIG_ICONS}\n${SCAN_ICONS}" | sort -u | sed '/^$/d')
ICON_COUNT=$(echo "$ALL_ICONS" | wc -l | tr -d ' ')
echo "Found $ICON_COUNT unique icon names"

# ── Write icon list to temp file for the Python script ──────────────────────
ICON_FILE=$(mktemp)
echo "$ALL_ICONS" > "$ICON_FILE"

# ── Write the Python subsetting script ──────────────────────────────────────
cat > "$SUBSET_SCRIPT" << 'PYTHON_SCRIPT'
#!/usr/bin/env python3
"""Two-pass Material Symbols font subsetting with ligature preservation."""

import sys
import os

# Add venv to path
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '.venv', 'lib'))

from fontTools.ttLib import TTFont
from fontTools.subset import Subsetter
from fontTools.varLib.instancer import instantiateVariableFont

digit_words = {
    str(i): ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'][i]
    for i in range(10)
}

def icon_to_ligature_variants(name):
    """Generate all possible ligature name forms for matching.
    
    Material Symbols uses character-level glyph names in ligatures:
    'diversity_3' → components: d,i,v,e,r,s,i,t,y,underscore,d,i,g,i,t,_,t,h,r,e,e
    The digit '3' maps to glyph 'digit_three' (or just 'three' after instancing).
    """
    forms = set()
    
    # Variant 1: Direct underscore substitution
    forms.add(name.replace('_', 'underscore'))
    
    # Variant 2: Digits → digit_<word>, underscores → underscore
    parts = name.split('_')
    converted = []
    for part in parts:
        new_part = ''
        for c in part:
            if c.isdigit():
                new_part += 'digit_' + digit_words[c]
            else:
                new_part += c
        converted.append(new_part)
    forms.add('underscore'.join(converted))
    
    # Variant 3: Digits → <word> only (after instancing, glyph names may simplify)
    parts2 = name.split('_')
    converted2 = []
    for part in parts2:
        new_part = ''
        for c in part:
            if c.isdigit():
                new_part += digit_words[c]
            else:
                new_part += c
        converted2.append(new_part)
    forms.add('underscore'.join(converted2))
    
    # Variant 4: Digits → plain <word> with underscore join (post-instancing GSUB fixup)
    # After instancing + GSUB fixup, digit_three→three, so ligatures use plain words
    parts3 = name.split('_')
    converted3 = []
    for part in parts3:
        new_part = ''
        for c in part:
            if c.isdigit():
                new_part += digit_words[c]
            else:
                new_part += c
        converted3.append(new_part)
    forms.add('underscore'.join(converted3))
    
    return forms


def main():
    source_font = sys.argv[1]
    codepoints_file = sys.argv[2]
    icon_file = sys.argv[3]
    output_font = sys.argv[4]
    
    # Read icon names
    with open(icon_file) as f:
        icon_names = set(line.strip() for line in f if line.strip())
    
    print(f"  Icons: {len(icon_names)}")
    
    # Read codepoints
    codepoints = {}
    with open(codepoints_file) as f:
        for line in f:
            parts = line.strip().split()
            if len(parts) == 2:
                codepoints[parts[0]] = int(parts[1], 16)
    
    # Build icon unicode set
    icon_unicode_set = set()
    for name in icon_names:
        cp = codepoints.get(name)
        if cp:
            icon_unicode_set.add(cp)
    
    # Build ligature name matching set
    icon_lig_names = set()
    for name in icon_names:
        icon_lig_names.update(icon_to_ligature_variants(name))
    
    # PASS 1: Text + unicode subset to preserve GSUB ligatures
    font = TTFont(source_font)
    subsetter = Subsetter()
    text_content = '\n'.join(sorted(icon_names))
    subsetter.populate(text=text_content)
    subsetter.populate(unicodes=icon_unicode_set)
    subsetter.subset(font)
    
    # Prune ligatures to only our icons
    total_kept = 0
    for feat in font['GSUB'].table.FeatureList.FeatureRecord:
        for idx in feat.Feature.LookupListIndex:
            lookup = font['GSUB'].table.LookupList.Lookup[idx]
            if lookup.LookupType == 7:
                for ext in lookup.SubTable:
                    inner = ext.ExtSubTable
                    if inner.LookupType == 4:
                        new_ligatures = {}
                        for first, lig_list in inner.ligatures.items():
                            new_list = [
                                lig for lig in lig_list
                                if (first + ''.join(getattr(lig, 'Component', []))) in icon_lig_names
                            ]
                            if new_list:
                                new_ligatures[first] = new_list
                                total_kept += len(new_list)
                        inner.ligatures = new_ligatures
    print(f"  Ligatures kept: {total_kept}")
    
    # Instantiate: remove GRAD and opsz axes (not used in our CSS)
    # Keep FILL (0/1 for active/filled states) and wght (400/700)
    instantiateVariableFont(font, {"GRAD": 0, "opsz": 24}, overlap=True, inplace=True)
    
    # Fix GSUB ligature component glyph names after instancing.
    # instancing renames digit_zero→zero, digit_one→one, etc.
    # but GSUB ligature component lists still reference the old names.
    glyph_order = set(font.getGlyphOrder())
    digit_rename = {f'digit_{word}': word for word in 
                    ['zero','one','two','three','four','five','six','seven','eight','nine']}
    renamed_count = 0
    for feat in font['GSUB'].table.FeatureList.FeatureRecord:
        for idx in feat.Feature.LookupListIndex:
            lookup = font['GSUB'].table.LookupList.Lookup[idx]
            if lookup.LookupType == 7:
                for ext in lookup.SubTable:
                    inner = ext.ExtSubTable
                    if inner.LookupType == 4:
                        for first, lig_list in inner.ligatures.items():
                            for lig in lig_list:
                                if hasattr(lig, 'Component'):
                                    new_comp = []
                                    for c in lig.Component:
                                        if c in digit_rename and digit_rename[c] in glyph_order:
                                            new_comp.append(digit_rename[c])
                                            renamed_count += 1
                                        else:
                                            new_comp.append(c)
                                    lig.Component = new_comp
    if renamed_count:
        print(f"  Fixed {renamed_count} post-instancing glyph references in GSUB")
    
    # Save intermediate
    font.flavor = 'woff2'
    intermediate = output_font + '.intermediate'
    font.save(intermediate)
    
    # PASS 2: Prune unused glyphs
    # Include both unicode codepoints AND text content to preserve
    # ligature target glyphs that may not have direct cmap entries
    unicode_set = set(icon_unicode_set)
    for cp in range(0x20, 0x7F):  # ASCII for ligature text
        unicode_set.add(cp)
    
    font2 = TTFont(intermediate)
    subsetter2 = Subsetter()
    subsetter2.populate(unicodes=unicode_set)
    subsetter2.populate(text=text_content)  # preserve ligature target glyphs
    subsetter2.subset(font2)
    
    # Drop unused tables
    for tag in list(font2.keys()):
        if tag in ['HVAR', 'MVAR']:
            del font2[tag]
    
    font2.flavor = 'woff2'
    font2.save(output_font)
    os.unlink(intermediate)
    
    # Verify
    size = os.path.getsize(output_font)
    print(f"  Output: {size:,} bytes ({size // 1024} KB)")
    
    # Check all icons have ligatures
    font3 = TTFont(output_font)
    lig_names_raw = set()
    for feat in font3['GSUB'].table.FeatureList.FeatureRecord:
        for idx in feat.Feature.LookupListIndex:
            lookup = font3['GSUB'].table.LookupList.Lookup[idx]
            if lookup.LookupType == 7:
                for ext in lookup.SubTable:
                    inner = ext.ExtSubTable
                    if inner.LookupType == 4:
                        for first, lig_list in inner.ligatures.items():
                            for lig in lig_list:
                                comp = getattr(lig, 'Component', [])
                                lig_names_raw.add(first + ''.join(comp))
    
    missing = []
    for icon in sorted(icon_names):
        found = any(raw in icon_to_ligature_variants(icon) for raw in lig_names_raw)
        if not found:
            missing.append(icon)
    
    if missing:
        print(f"  WARNING: {len(missing)} icons missing ligatures: {missing}")
        return 1
    
    # Report axes
    if 'fvar' in font3:
        axes = [(a.axisTag, a.minValue, a.maxValue) for a in font3['fvar'].axes]
        print(f"  Axes: {axes}")
    
    print(f"  ✓ All {len(icon_names)} icons verified")
    return 0

if __name__ == '__main__':
    sys.exit(main())
PYTHON_SCRIPT

# ── Run the Python subsetting script ────────────────────────────────────────
echo "Running two-pass subsetting..."
source "$VENV/bin/activate"
python3 "$SUBSET_SCRIPT" "$SOURCE_FONT" "$CODEPOINTS" "$ICON_FILE" "$OUTPUT_FONT"
RESULT=$?

# Cleanup
rm -f "$ICON_FILE"

if [[ $RESULT -ne 0 ]]; then
    echo -e "${RED}Subsetting failed!${NC}"
    exit 1
fi

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
