# Roundup Games — Design System Snapshot

> Extracted from codebase for presentation/materials generation. All values are production source-of-truth.

---

## Design Philosophy

**Name:** "The Tactile Hearth"

**Concept:** A "Digital Parlor" — warm, inviting, editorial. Built around the metaphor of gathering around a table. Every surface, shadow, and interaction is designed to feel tactile and human, not corporate or SaaS-like. The design deliberately avoids competitive/gamification language in favor of belonging, curiosity, and safety.

**Brand tagline:** "There's a seat waiting for you."

---

## Color System

Full Material Design 3–inspired token system stored as RGB channels in CSS custom properties. This enables Tailwind's opacity modifier syntax (`bg-primary/10`, `text-on-surface/50`).

### Light Mode (Default)

| Token | RGB | Hex (approx) | Usage |
|-------|-----|-------------|-------|
| **Primary** | `131 85 0` | `#835500` | Amber/gold — buttons, active states, headings |
| **Primary Container** | `245 166 35` | `#F5A623` | Lighter amber — secondary surfaces |
| **On Primary** | `255 255 255` | `#FFFFFF` | Text on primary |
| **On Primary Container** | `100 64 0` | `#644000` | Text on primary container |
| **Secondary** | `0 96 172` | `#0060AC` | Blue — secondary actions, links |
| **Secondary Container** | `104 171 255` | `#68ABFF` | Light blue — secondary buttons |
| **Tertiary** | `148 73 37` | `#944925` | Terracotta — accent highlights |
| **Tertiary Container** | `255 158 115` | `#FF9E73` | Light terracotta |
| **Surface** | `251 249 241` | `#FBF9F1` | **Cream** — main background |
| **Surface Dim** | `220 218 210` | `#DCDAD2` | Recessed surfaces |
| **Surface Container** | `240 238 230` | `#F0EEE6` | Card backgrounds |
| **Surface Container Lowest** | `255 255 255` | `#FFFFFF` | Elevated cards |
| **Surface Container Low** | `245 244 236` | `#F5F4EC` | Sidebar background |
| **Surface Container High** | `234 232 224` | `#EAE8E0` | Input backgrounds |
| **Surface Variant** | `228 227 219` | `#E4E3DB` | Borders, dividers |
| **On Surface** | `27 28 23` | `#1B1C17` | Primary text |
| **On Surface Variant** | `82 69 52` | `#524534` | Secondary text (warm brown) |
| **Outline** | `133 116 98` | `#857462` | Borders |
| **Outline Variant** | `215 195 174` | `#D7C3AE` | Subtle borders |
| **Error** | `186 26 26` | `#BA1A1A` | Error states |
| **Inverse Surface** | `48 49 44` | `#30312C` | Dark accent bands |
| **Inverse On Surface** | `243 241 233` | `#F3F1E9` | Text on inverse surface |
| **Inverse Primary** | `255 185 85` | `#FFB955` | Primary on dark bands |

### Dark Mode

| Token | RGB | Hex (approx) | Usage |
|-------|-----|-------------|-------|
| **Primary** | `255 185 85` | `#FFB955` | Warm amber — inverted for dark |
| **Surface** | `27 28 23` | `#1B1C17` | **Warm dark** — main background |
| **Surface Dim** | `19 20 16` | `#131410` | Deepest dark |
| **Surface Bright** | `42 43 36` | `#2A2B24` | Elevated dark surfaces |
| **On Surface** | `228 227 219` | `#E4E3DB` | Light text on dark |
| **On Surface Variant** | `198 192 176` | `#C6C0B0` | Secondary text on dark |

### Color Hierarchy

```
Primary (amber)     → Actions, CTAs, active navigation, headings
Secondary (blue)    → Secondary buttons, links, input focus rings
Tertiary (terracotta) → Accent highlights, decorative
Surface (cream)     → Every background, card, container
Inverse (dark warm) → Stats bands, trust badges, contrast sections
Error (red)         → Validation, destructive actions
```

---

## Typography

### Font Families

| Role | Font | Fallback | Weights | Loading |
|------|------|----------|---------|---------|
| **Body / UI** | Inter | Helvetica Neue, Arial, sans-serif | 100–900 (variable) | Self-hosted WOFF2, split by unicode-range (latin + latin-ext) |
| **Headings** | Noto Serif | Georgia, Times New Roman, serif | 100–900 (variable) + italic | Self-hosted WOFF2, split by unicode-range |
| **Icons** | Material Symbols Outlined | — | 100–700 (variable FILL+wght) | Self-hosted subset (~160KB vs 1.1MB full) |

### Font Loading Strategy

- `font-display: swap` for all faces
- CLS-prevention fallback `@font-face` declarations with `ascent-override`, `descent-override`, `line-gap-override`, `size-adjust` metrics tuned to match Inter and Noto Serif
- Split unicode-range loading: latin-ext loaded first (wider coverage for en+de), then latin

### Typographic Scale

Headings use `font-heading` (Noto Serif) with `letter-spacing: -0.02em`. Body uses `font-sans` (Inter).

| Context | Classes | Size |
|---------|---------|------|
| Hero H1 | `text-4xl sm:text-5xl lg:text-6xl font-heading font-bold tracking-tight leading-tight` | 36→48→60px |
| Section H2 | `text-3xl sm:text-4xl font-heading font-bold tracking-tight` | 30→36px |
| Card H3 | `font-heading font-semibold text-lg` | 18px |
| Body | `text-base` (default Inter) | 16px |
| Body secondary | `text-sm text-on-surface-variant` | 14px |
| Caption/helper | `text-xs text-on-surface-variant` | 12px |
| Stat numbers | `text-3xl sm:text-4xl font-heading font-bold text-inverse-primary` | 30→36px |

---

## Shadows

| Name | Value | Usage |
|------|-------|-------|
| `shadow-ambient` | `0 12px 40px rgba(82, 69, 52, 0.06)` | Default card shadow — ultra-diffused warm brown |
| `shadow-ambient-md` | `0 4px 16px rgba(82, 69, 52, 0.08)` | Hover state elevation |
| `shadow-ambient-lg` | `0 20px 60px rgba(82, 69, 52, 0.10)` | High-elevation modals/overlays |
| Dark mode ambient | `0 12px 40px rgba(0, 0, 0, 0.25)` | Switches to neutral black shadow |

**Key principle:** All shadows are tinted warm brown (rgba 82,69,52) in light mode, never pure black. This matches the cream/amber palette and prevents the "cold shadow" look.

CSS class: `.editorial-shadow { box-shadow: var(--shadow-ambient); }`

---

## Border Radius

| Token | Value | Usage |
|-------|-------|-------|
| `rounded-sm` | 0.25rem (4px) | Badges, small elements |
| `rounded-md` | 0.75rem (12px) | Inputs |
| `rounded-lg` | 1rem (16px) | — |
| `rounded-xl` | 1.5rem (24px) | Buttons, cards, navigation items |
| `rounded-2xl` | 2rem (32px) | Large containers, modals |
| `rounded-full` | 9999px | Avatars, pills |

---

## Spacing & Layout

| Pattern | Value |
|---------|-------|
| Max content width | `max-w-6xl` (1152px) |
| Page horizontal padding | `px-4 sm:px-6` (16→24px) |
| Section vertical padding | `py-16 sm:py-20` (64→80px) |
| Card padding | `p-4` to `p-6` (16→24px) |
| Sidebar width | `w-64` (256px) |
| Mobile header height | `h-16` (64px) |
| Grid gap | `gap-4 sm:gap-6` to `gap-8` |
| Hero padding | `py-20 sm:py-28 lg:py-36` |

---

## Component Patterns

### Buttons

| Style | Class | Appearance |
|-------|-------|------------|
| **Primary** | `.btn-brand` | Amber bg, white text, xl radius, heading font, hover brightness-110 |
| **Secondary** | `.btn-brand-outline` | Blue container bg, xl radius, heading font |
| **Google OAuth** | `.btn-google` | White bg, outline-variant border, full-width |
| **Ghost/Outline** | `border border-outline text-on-surface-variant rounded-xl` | Transparent bg, outline border |

Primary button markup:
```html
<a class="inline-flex items-center px-6 py-3 bg-primary text-on-primary rounded-xl 
   font-semibold hover:brightness-110 transition-all text-sm shadow-md">
  <span class="material-symbols-outlined mr-2 text-lg">icon_name</span>
  Button Text
</a>
```

Secondary outline button:
```html
<a class="inline-flex items-center px-6 py-3 bg-on-primary/20 text-on-primary rounded-xl 
   font-semibold hover:bg-on-primary/30 transition-colors text-sm 
   border border-on-primary/30">
  Button Text
</a>
```

### Cards

**Standard card:**
```
bg-surface-container-lowest rounded-xl shadow-ambient overflow-hidden
hover:shadow-ambient-md transition-shadow duration-200
```

**Card with header band (events):**
```
Header: bg-primary px-4 py-3 (amber band)
Body: p-4 (content area)
Footer: pt-3 bg-surface-container-low (tonal separation, no border)
```

**Value/feature card:**
```
bg-surface-container-lowest rounded-xl p-6 shadow-ambient text-center
+ Icon: w-14 h-14 bg-primary/10 rounded-full (amber tint circle)
```

### Navigation

**Sidebar (desktop):**
- Background: `bg-surface-container-low` (light cream)
- Active item: `bg-surface-container-lowest text-primary font-bold` + filled icon
- Inactive item: `text-on-surface-variant hover:bg-surface-container-high hover:text-primary`
- Item shape: `px-4 py-3 rounded-xl`
- Icon + text gap: `gap-3`
- Active indicator: Material Symbols `FILL` axis set to 1

**Mobile nav:** Full-width dropdown with same item styling, `bg-surface/95 backdrop-blur-md`.

### Form Inputs

`.input-brand` — cream background, transparent border, rounded-md, secondary-blue focus ring.

Dark mode override: inputs switch to `surface-container-high` background with `outline-variant` borders.

### Avatars

- Size variants: `w-8 h-8` (small), default, larger
- Shape: `rounded-full`
- Background: `bg-primary/10` (amber tint)
- Fallback: User initial in primary color, heading font
- Image: `object-cover` with fallback-to-initial via JS

### Badges & Pills

- **GM Badge:** `bg-primary/10 text-primary rounded-full` + filled Material icon `school`
- **Language chip:** Small pill with language code
- **Status badges:** `text-xs font-medium uppercase` with contextual color
- **Distance badge:** `text-xs bg-primary/10 text-primary rounded-full px-2 py-0.5`

### Glass Overlay

`.glass-overlay` — frosted glass effect adapting to light/dark:
- Light: `rgba(251, 249, 241, 0.8)` cream glass + `blur(24px)`
- Dark: `rgba(27, 28, 23, 0.85)` warm dark glass + `blur(24px)`

### Modals & Dropdowns

```
bg-surface-container-lowest rounded-xl shadow-ambient
border border-outline-variant/15
```

Alpine.js transitions: `ease-out duration-150` enter, `ease-in duration-100` leave.

---

## Page Layout Patterns

### Public Pages (marketing/content)

```
┌─────────────────────────────────────────┐
│ [Logo]          [Nav Links] [Theme] [Auth] │  ← Transparent header
├─────────────────────────────────────────┤
│                                         │
│  Hero (primary bg, white text)          │  ← Full-bleed amber
│  Decorative circles (on-primary/10)     │
│                                         │
├─────────────────────────────────────────┤
│                                         │
│  Content sections (surface bg)          │  ← Alternating surface tones
│  Cards in grid (2-4 columns)            │
│                                         │
├─────────────────────────────────────────┤
│  Stats band (inverse-surface bg)        │  ← Dark warm band
│  Trust badges (inverse-surface bg)      │
├─────────────────────────────────────────┤
│  CTA section (surface bg)               │
│  [Primary Button] [Ghost Button]        │
└─────────────────────────────────────────┘
```

### Authenticated Pages (app)

```
┌──────────┬──────────────────────────────┐
│ Sidebar  │  Content Area                │
│ w-64     │                              │
│          │  max-w-6xl centered          │
│ [Logo]   │                              │
│ ──────── │  [Page Header]              │
│ Dashboard│  [Content / Cards / Forms]   │
│ Games    │                              │
│ Campaigns│                              │
│ People   │                              │
│ ──────── │                              │
│ GM Space │                              │
│ Billing  │                              │
│ ──────── │                              │
│ [Avatar] │                              │
│ [Theme]  │                              │
│ [Locale] │                              │
│ [Logout] │                              │
└──────────┴──────────────────────────────┘
```

---

## Icon System

**Icon font:** Material Symbols Outlined (self-hosted subset)

**Subset strategy:** Only icons used in templates are included (~160KB vs 1.1MB full). Managed via `config/fonts.php` and rebuilt with `build-tools/subset-icons.sh`.

**Icon usage:** `<span class="material-symbols-outlined" aria-hidden="true">icon_name</span>`

**Active state:** Filled icon via inline `style="font-variation-settings: 'FILL' 1"`

**Common icons by domain:**

| Domain | Icons |
|--------|-------|
| Navigation | `dashboard`, `stadium`, `campaign`, `group`, `casino`, `account_balance_wallet`, `notifications` |
| Discovery | `explore`, `search`, `casino`, `location_on` |
| Actions | `person_add`, `edit`, `delete`, `share`, `link`, `content_copy` |
| Status | `check_circle`, `cancel`, `schedule`, `hourglass_empty` |
| Trust/Safety | `shield_person`, `volunteer_activism`, `groups`, `code` |
| Theme | `light_mode`, `dark_mode`, `routine` |

---

## Dark Mode

- **Strategy:** `class` based (Tailwind `darkMode: 'class'`)
- **Toggle:** Three-way: Light / Dark / System (follows OS preference)
- **Persistence:** `localStorage.theme`
- **Flash prevention:** Inline `<script>` in `<head>` applies `.dark` class before paint
- **Critical background:** Inline `<style>` sets body background for both modes before CSS loads

### Dark Mode Color Shifts

| Element | Light | Dark |
|---------|-------|------|
| Primary | Amber #835500 | Light amber #FFB955 |
| Background | Cream #FBF9F1 | Warm dark #1B1C17 |
| Cards | White #FFFFFF | Dark #2A2B24 |
| Text | Near-black #1B1C17 | Warm light #E4E3DB |
| Shadows | Brown-tinted | Pure black (higher opacity) |
| Glass overlay | Cream 80% | Dark 85% |

---

## Brand Assets

### Logo

Dual-variant (light/dark background), dual-resolution (nav vs full):

| Context | File | Size |
|---------|------|------|
| Nav (light bg) | `/images/logo-light-background-nav.webp` | 150×82px |
| Nav (dark bg) | `/images/logo-dark-background-nav.webp` | 150×82px |
| Full (light bg) | `/images/logo-light-background.webp` | 339×185px |
| Full (dark bg) | `/images/logo-dark-background.webp` | 339×185px |

**Text fallback** (used when images cannot load): `Roundup` (primary color) + `Games` (on-surface color), heading font, bold.

### Favicon & PWA

- `/icons/favicon-32x32.png`
- `/icons/favicon-16x16.png`
- `/icons/apple-touch-icon.png`
- Theme color: `#835500`
- Manifest: `/manifest.json`

---

## Animation & Transitions

| Pattern | Timing | Usage |
|---------|--------|-------|
| Button hover | `150ms ease-in-out` | Brightness shift |
| Shadow elevation | `200ms` | Card hover |
| Navigation dropdown | `200ms ease-out` enter, `150ms ease-in` leave | Alpine.js |
| Mobile nav | `200ms ease-out` enter | Slide + fade |
| Page transitions | SPA via `wire:navigate` | Livewire 3, no full reload |
| PWA install prompt | `300ms ease-out` enter, `200ms ease-in` leave | Slide up + fade |
| Notification polling | 30s interval | Livewire polling |

---

## Design Tokens Summary (for code/config reference)

All tokens are CSS custom properties on `:root` (light) and `.dark` (dark), consumed via Tailwind config:

```css
/* Pattern: --rgb-{name}: R G B */
/* Tailwind: rgb(var(--rgb-{name}) / <alpha-value>) */

/* Core surface */
--rgb-background: 251 249 241;     /* cream */
--rgb-on-background: 27 28 23;     /* warm black */

/* Primary amber */
--rgb-primary: 131 85 0;           /* #835500 */
--rgb-on-primary: 255 255 255;

/* Shadows */
--shadow-ambient: 0 12px 40px rgba(82, 69, 52, 0.06);

/* Glass */
--glass-bg: rgba(251, 249, 241, 0.8);  /* light */
--glass-bg: rgba(27, 28, 23, 0.85);    /* dark */
```
