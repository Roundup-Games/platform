<?php

/**
 * Raw-string triage guard — flags user-facing copy in first-party Blade views
 * that is hardcoded instead of going through lang/ translation keys.
 *
 * Modeled on the 2026-10 content audit scanner (M066/S02/T10). Pure
 * filesystem scan — no database, no Laravel bootstrap — safe for CI smoke.
 *
 * What it flags:
 *   - Literal text nodes between HTML tags ("Cancel game", "No sessions yet")
 *   - Static values of user-visible attributes (placeholder, title, alt,
 *     aria-label, aria-description) — bound (:attr) or {{ }}-interpolated
 *     values are dynamic and skipped
 *
 * What it never flags (built-in exclusions, see isAutoSkipped()):
 *   - Blade comments, @php blocks, <script>/<style> bodies (masked first)
 *   - {{ }} / {!! !!} interpolations (already keyed or computed)
 *   - Material Symbols icon ligatures (single lowercase snake_case tokens
 *     such as arrow_back inside icon spans)
 *   - HTML entities, punctuation/number-only fragments, emails, URLs,
 *     code leftovers (braces, $vars, =>)
 *
 * Intentional raw strings are allowed via the explicit allowlist below —
 * every entry carries a reason. Exit 0 only when nothing unexplained remains.
 *
 * Excluded directories (EN-only admin/package surfaces):
 *   resources/views/filament    — Filament admin (EN-only per K002 exemption)
 *   resources/views/escalated   — escalated* package views (EN-only internal)
 *   resources/views/vendor      — vendor-published package views (regenerated
 *                                 by vendor:publish; not first-party copy)
 *
 * Usage: php scripts/check-raw-strings.php   (exit 0 = clean, 1 = findings)
 */

declare(strict_types=1);

const SCAN_ROOT = 'resources/views';

/** Directories under SCAN_ROOT that are not first-party localized surfaces. */
const EXCLUDED_DIRS = ['filament', 'escalated', 'vendor'];

/**
 * Attribute names whose static literal values reach users.
 * Bound attributes (:placeholder="...") are excluded via regex lookbehind.
 */
const SCANNED_ATTRIBUTES = 'placeholder|title|alt|aria-label|aria-description';

/**
 * Exact-match allowlist: lowercase trimmed candidate => reason it may stay raw.
 * Keep sorted. Prefer keying real copy over growing this list.
 */
const ALLOWLIST_EXACT = [
    'ddg' => 'statute abbreviation (legal citation)',
    'discord' => 'brand name, never translated',
    'eur' => 'currency code',
    'gm' => 'canonical term, identical in both locales (glossary canon)',
    'github' => 'brand name, never translated',
    'google' => 'brand name, never translated',
    'id' => 'technical abbreviation, identical in both locales',
    'mstv' => 'statute abbreviation (legal citation)',
    'ok' => 'interjection identical in both locales',
    'participants desc, created_at desc' => 'SQL sort notation (font-mono technical spec on pledge-algorithms)',
    'roundup games' => 'brand name, never translated',
    'utc' => 'timezone abbreviation',
    'x-card' => 'canonical safety-tool term, identical in both locales (glossary canon)',
];

/**
 * Pattern allowlist: regex => reason. Anchored where possible to stay narrow.
 */
const ALLOWLIST_PATTERNS = [
    // Accessibility attribute fragments, not rendered copy
    '/^aria-[a-z-]+$/i' => 'aria attribute fragment, not rendered copy',
    // Bare domain URLs (roundup.games/u/, mastodon.social, tinyurl.com/...)
    '/^[a-z0-9-]+(\.[a-z0-9-]+)+([\/?#]\S*)?$/i' => 'bare domain URL — identical in both locales',
    // Formula blocks and grid-size notation on pledge-algorithms
    '/=|×|√|Δ|&times;|&radic;|&Delta;|&sup2;|&#832[0-9];/u' => 'mathematical/technical notation, locale-neutral',
    // Adjacent Material Symbols spans (incl. multi-line @if icon variants);
    // sentence-case prose never matches because it starts capitalized
    '/^[a-z0-9_]+(\s+[a-z0-9_]+)*$/' => 'icon ligature sequence (Material Symbols), not user copy',
];

/**
 * Per-file allowlist for fragments that are only intentional in context
 * (brand wordmark spans, code samples). Path relative to SCAN_ROOT.
 */
const FILE_ALLOWLIST = [
    'components/application-logo.blade.php' => [
        'roundup' => 'brand wordmark span (logo lockup)',
        'games' => 'brand wordmark span (logo lockup)',
    ],
];

/**
 * A raw-string finding.
 */
final class RawStringFinding
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $candidate,
        public readonly string $sourceLine,
    ) {}
}

/**
 * Replace every byte of [$offset, $offset+$length) with a space, preserving
 * newlines, so all later regex offsets stay valid. Returns the new document.
 */
function maskSpan(string $doc, int $offset, int $length): string
{
    $out = substr($doc, 0, $offset);
    for ($i = 0; $i < $length; $i++) {
        $out .= $doc[$offset + $i] === "\n" ? "\n" : ' ';
    }

    return $out.substr($doc, $offset + $length);
}

/**
 * Collect [start, length] spans that are not user copy: Blade comments,
 *
 * @php blocks, <script>/<style> interiors, {{ }}/{!! !!} interpolations and
 *
 * @directive(...) tokens (balanced parens, quote-aware) incl. Alpine
 *
 * @event="..." attribute handlers. Returns the masked document with all spans
 * replaced by spaces (newlines preserved) so copy offsets stay valid.
 */
function collectMaskSpans(string $doc): string
{
    $spans = [];

    $push = function (int $start, int $end) use (&$spans): void {
        if ($end > $start) {
            $spans[] = [$start, $end - $start];
        }
    };

    // Blade comments {{-- ... --}} and @php ... @endphp blocks
    foreach (['/\{\{--.*?--\}\}/s', '/@php\b.*?@endphp/s'] as $re) {
        if (preg_match_all($re, $doc, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$spanText, $offset]) {
                $push((int) $offset, (int) $offset + strlen((string) $spanText));
            }
        }
    }

    // <script>/<style> interiors
    foreach (['script', 'style'] as $tag) {
        if (preg_match_all('/<'.$tag.'\b[^>]*>(.*?)<\/'.$tag.'>/s', $doc, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$inner, $offset]) {
                $push((int) $offset, (int) $offset + strlen((string) $inner));
            }
        }
    }

    // {{ ... }} and {!! ... !!} interpolations
    if (preg_match_all('/\{\{.*?\}\}|\{!!.*?!\}/s', $doc, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$spanText, $offset]) {
            $push((int) $offset, (int) $offset + strlen((string) $spanText));
        }
    }

    // @directive tokens (Blade + Alpine), with balanced (...) args or ="..."
    if (preg_match_all('/(?<![\w@])@[a-zA-Z_][a-zA-Z0-9_.-]*/', $doc, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$token, $tokOffset]) {
            $start = (int) $tokOffset;
            $len = strlen((string) $token);
            $i = $start + $len;
            // Skip whitespace
            while ($i < strlen($doc) && ctype_space($doc[$i])) {
                $i++;
            }
            if ($i < strlen($doc) && $doc[$i] === '(') {
                // Balanced-paren walk, quote-aware.
                $depth = 0;
                $quote = null;
                for ($j = $i; $j < strlen($doc); $j++) {
                    $ch = $doc[$j];
                    if ($quote !== null) {
                        if ($ch === '\\') {
                            $j++;
                        } elseif ($ch === $quote) {
                            $quote = null;
                        }

                        continue;
                    }
                    if ($ch === "'" || $ch === '"') {
                        $quote = $ch;
                    } elseif ($ch === '(') {
                        $depth++;
                    } elseif ($ch === ')') {
                        $depth--;
                        if ($depth === 0) {
                            $push($start, $j + 1);

                            continue 2;
                        }
                    }
                }
                // Unbalanced — mask just the token.
                $push($start, $start + $len);
            } elseif ($i + 1 < strlen($doc) && $doc[$i] === '=' && ($doc[$i + 1] === '"' || $doc[$i + 1] === "'")) {
                // Alpine @event="..." handler value.
                $quote = $doc[$i + 1];
                $close = strpos($doc, $quote, $i + 2);
                $push($start, $close === false ? $start + $len : $close + 1);
            } else {
                $push($start, $start + $len);
            }
        }
    }

    // Apply spans right-to-left so offsets stay valid.
    usort($spans, fn (array $a, array $b): int => $b[0] <=> $a[0]);
    $masked = $doc;
    foreach ($spans as [$offset, $length]) {
        $masked = maskSpan($masked, $offset, $length);
    }

    return $masked;
}

/**
 * Built-in (non-allowlist) skip rules: fragments that can never be user copy.
 */
function isAutoSkipped(string $candidate): bool
{
    $text = trim($candidate);

    if ($text === '') {
        return true;
    }

    // Strip HTML entities, then re-trim.
    $text = trim(preg_replace('/&[a-zA-Z][a-zA-Z0-9]*;/', ' ', $text) ?? $text);
    if ($text === '') {
        return true;
    }

    // Needs at least one letter to be copy.
    if (! preg_match('/[A-Za-z]/', $text)) {
        return true;
    }

    // Code leftovers from Alpine/JS fragments leaking through markup.
    if (str_contains($text, '{') || str_contains($text, '}') || str_contains($text, '$') || str_contains($text, '=>')) {
        return true;
    }

    // Emails and URLs are identifiers, not copy.
    if (preg_match('/^[\w.+-]+@[\w.-]+$/', $text) || preg_match('/^(https?:\/\/|www\.|mailto:)/i', $text)) {
        return true;
    }

    // Single lowercase snake/kebab token with no spaces or dots: icon ligature
    // (arrow_back, chevron_right) or technical identifier — never prose.
    if (preg_match('/^[a-z0-9][a-z0-9_\-]*$/', $text)) {
        return true;
    }

    return false;
}

/**
 * Is the candidate English-looking prose? Requires a 3+ letter run or two
 * shorter letter runs separated by non-letters ("of", "to do").
 */
function isPotentiallyCopy(string $candidate): bool
{
    return (bool) preg_match('/[A-Za-z]{3,}/', $candidate)
        || (bool) preg_match('/[A-Za-z]{2}[^A-Za-z]+[A-Za-z]{2}/', $candidate);
}

/**
 * @param  int[]  $newlinePositions  sorted positions of "\n" in the document
 */
function lineForOffset(array $newlinePositions, int $offset): int
{
    $line = 1;
    foreach ($newlinePositions as $pos) {
        if ($pos >= $offset) {
            break;
        }
        $line++;
    }

    return $line;
}

/**
 * @return RawStringFinding[]
 */
function scanFile(string $path, string $relative): array
{
    $original = file_get_contents($path);
    if ($original === false) {
        return [];
    }

    $newlinePositions = [];
    $pos = 0;
    while (($pos = strpos($original, "\n", $pos)) !== false) {
        $newlinePositions[] = $pos;
        $pos++;
    }

    $masked = collectMaskSpans($original);
    $findings = [];
    $fileAllowlist = FILE_ALLOWLIST[substr($relative, strlen(SCAN_ROOT) + 1)] ?? [];

    $consider = function (string $candidate, int $offset) use ($relative, $original, $newlinePositions, $fileAllowlist, &$findings): void {
        $candidate = trim($candidate);
        if ($candidate === '' || isAutoSkipped($candidate) || ! isPotentiallyCopy($candidate)) {
            return;
        }

        if (array_key_exists(mb_strtolower($candidate), ALLOWLIST_EXACT)
            || array_key_exists(mb_strtolower($candidate), $fileAllowlist)) {
            return;
        }
        foreach (ALLOWLIST_PATTERNS as $pattern => $reason) {
            if (preg_match($pattern, $candidate) === 1) {
                return;
            }
        }

        $line = lineForOffset($newlinePositions, $offset);
        $sourceLine = explode("\n", $original)[$line - 1] ?? '';
        $findings[] = new RawStringFinding($relative, $line, $candidate, trim($sourceLine));
    };

    // Text nodes between tags.
    if (preg_match_all('/>([^<>]+)</', $masked, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[1] as [$text, $offset]) {
            $consider((string) $text, (int) $offset);
        }
    }

    // Static user-visible attribute values (double and single quoted).
    foreach (['"', "'"] as $quote) {
        $attr = '/(?<![:\w-])('.SCANNED_ATTRIBUTES.')(\\.[a-z.-]+)?\\s*=\\s*'.$quote.'([^'.$quote.']*)'.$quote.'/i';
        if (preg_match_all($attr, $masked, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[3] as [$value, $offset]) {
                $consider((string) $value, (int) $offset);
            }
        }
    }

    return $findings;
}

/**
 * @return string[]
 */
function collectBladeFiles(string $dir): array
{
    $files = [];
    $stack = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($stack as $file) {
        if (! ($file instanceof SplFileInfo) || ! $file->isFile()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($dir) + 1);
        $topDir = explode('/', $relative)[0];
        if (in_array($topDir, EXCLUDED_DIRS, true)) {
            continue;
        }
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

function main(): int
{
    $root = __DIR__.'/../'.SCAN_ROOT;
    if (! is_dir($root)) {
        fwrite(STDERR, "Scan root not found: {$root}\n");

        return 2;
    }

    $findings = [];
    $scanned = 0;
    foreach (collectBladeFiles($root) as $file) {
        $scanned++;
        $relative = SCAN_ROOT.'/'.substr($file, strlen($root) + 1);
        $findings = [...$findings, ...scanFile($file, $relative)];
    }

    if ($findings === []) {
        echo "Raw-string check: clean ({$scanned} first-party Blade files scanned, 0 untriaged raw strings).\n";

        return 0;
    }

    echo 'Raw-string check: '.count($findings)." untriaged raw string(s) in first-party views.\n";
    echo "Key real copy into lang/ (en+de) or add an allowlisted reason in scripts/check-raw-strings.php.\n\n";

    $byFile = [];
    foreach ($findings as $f) {
        $byFile[$f->file][] = $f;
    }
    ksort($byFile);
    foreach ($byFile as $file => $items) {
        echo "  {$file}\n";
        foreach ($items as $item) {
            echo "    line {$item->line}: \"{$item->candidate}\"\n";
            echo "      > {$item->sourceLine}\n";
        }
    }

    return 1;
}

exit(main());
