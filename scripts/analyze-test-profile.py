#!/usr/bin/env python3
"""Analyze test-harness performance artifacts and emit the report bundle.

Companion to scripts/test-perf-report.sh (one-command pipeline; join
semantics validated on the 2026-11-02 reference bundle,
storage/app/test-report/2026-11-02/).

Inputs — all read from a report directory storage/app/test-report/<date>/:

  junit-seq-*.xml  per-chunk sequential junit (authoritative per-test time,
                   assertions, file attribution); also merged into
                   junit-seq.xml as a convenience artifact for other tooling
  profile-seq.csv  per-test profiler rows appended by tests/Pest.php under
                   TEST_PROFILE=1 (Feature extension only):
                   file,test,duration_ms,queries,mem_delta_kb
  junit-par.xml    parallel run junit (comparison totals; optional)
  runner.log       chunk wall times, cold floor, smoke file count, commit
  smoke-files.txt  files tagged group('smoke') (subset timing; optional)

The junit and profiler streams are not perfectly joinable (the CSV covers
only the Feature extension; junit covers everything), so they join on test
name: both sides are collapsed to slugs (Pest mangles display names
differently in each stream), probed exact-first with an endswith fallback;
rows with multiple candidates resolve to the candidate whose duration_ms is
closest to the junit time.

Outputs — report dir /analysis/: report-data.md, per-dir.csv, per-file.csv,
per-test.csv, datasets.csv.

Usage:
  python3 scripts/analyze-test-profile.py [report_dir]

report_dir defaults to the newest <YYYY-MM-DD> directory under
storage/app/test-report/. Pass a directory to re-analyze an existing
bundle without re-running the suite. Python 3 stdlib only.
"""
import csv
import glob
import os
import re
import sys
import xml.etree.ElementTree as ET

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REPORTS_ROOT = os.path.join(ROOT, 'storage', 'app', 'test-report')


def default_report_dir():
    """Newest strict YYYY-MM-DD directory under storage/app/test-report/."""
    dated = [
        d for d in glob.glob(os.path.join(REPORTS_ROOT, '*'))
        if os.path.isdir(d) and re.fullmatch(r'\d{4}-\d{2}-\d{2}', os.path.basename(d))
    ]
    if not dated:
        sys.exit(f'analyze-test-profile: no report dir under {REPORTS_ROOT} — pass one explicitly')
    return max(dated)


# ── name normalization (Pest display-name mangling) ─────────────────────────
def slug(s):
    return re.sub(r'[^0-9A-Za-z]+', '_', s).strip('_')


def junit_key(name):
    """junit display name -> ('group-slug', 'desc-slug') or (None, slug)."""
    n = name.replace('`', '')
    if ' with data set ' in n:
        n = n.split(' with data set ')[0]
    if ' → ' in n:
        g, d = n.split(' → ', 1)
        return (slug(g), slug(d))
    return (None, slug(n))


def csv_norm(name):
    """profiler CSV mangled name -> normalized 'Group__desc' or 'desc' slug."""
    n = re.sub(r'^__pest_evaluable_+', '', name)
    n = n.replace('__→_', '__')
    n = re.sub(r'_+with_data_set_.*$', '', n)
    return slug(n)


def class_to_file(cls):
    """Tests\\Feature\\X\\Y classname -> tests/Feature/X/Y.php when it exists."""
    if not cls or '\\' not in cls:
        return None
    rel = cls.replace('\\', '/') + '.php'
    p = rel[rel.find('Tests/'):]
    for cand in (p, re.sub(r'\.php$', 'Test.php', p)):
        rel_tests = cand.replace('Tests/', 'tests/', 1)
        if os.path.isfile(os.path.join(ROOT, rel_tests)):
            return rel_tests
    return None


def norm_file(path, cls=None, suite_name=None):
    if path:
        p = path.split('::')[0]
        i = p.find('tests/')
        if i >= 0:
            return p[i:]
        m = re.search(r'\(([^)]+)\)\s*$', p)
        if m:
            f = class_to_file(m.group(1))
            if f:
                return f
    if cls:
        f = class_to_file(cls)
        if f:
            return f
    if suite_name:
        f = class_to_file(suite_name)
        if f:
            return f
    return '?'


def suite_dir(path):
    r = norm_file(path)
    if r == '?':
        return '(no-file)'
    parts = r.split('/')
    return '/'.join(parts[:-1]) if len(parts) > 1 else '(root)'


def pct(x, total):
    return f'{(100.0 * x / total):.1f}%' if total else '0.0%'


def ms(x):
    return f'{x:.0f}'


def sec(x):
    return f'{x / 1000.0:.1f}s'


# ── locate the report bundle ────────────────────────────────────────────────
if len(sys.argv) > 1 and sys.argv[1] in ('-h', '--help'):
    print(__doc__)
    sys.exit(0)
R = os.path.abspath(sys.argv[1]) if len(sys.argv) > 1 else default_report_dir()
OUT = os.path.join(R, 'analysis')
os.makedirs(OUT, exist_ok=True)

# ── merge chunk junit files into junit-seq.xml (convenience artifact) ──────
# The chunk files are authoritative for analysis; junit-seq.xml is the
# single-file view other tooling can consume.
junit_files = sorted(glob.glob(os.path.join(R, 'junit-seq-*.xml')))
if not junit_files:
    fallback = os.path.join(R, 'junit-seq.xml')
    if os.path.isfile(fallback):
        junit_files = [fallback]
    else:
        sys.exit(f'analyze-test-profile: no junit-seq-*.xml (or junit-seq.xml) in {R}')
else:
    merged_root = ET.Element('testsuites')
    for jf in junit_files:
        for child in ET.parse(jf).getroot():
            merged_root.append(child)
    merged_path = os.path.join(R, 'junit-seq.xml')
    ET.ElementTree(merged_root).write(merged_path, encoding='utf-8', xml_declaration=True)
    print(f'merged {len(list(merged_root))} suites -> {merged_path}')

# ── parse junit (leaf suites only: direct-child testcases) ─────────────────
tests = []
seen_tc = set()
for jf in junit_files:
    for ts in ET.parse(jf).getroot().iter('testsuite'):
        for tc in ts.findall('testcase'):
            uid = (tc.get('file'), tc.get('name'), tc.get('line'), tc.get('time'))
            if uid in seen_tc:
                continue
            seen_tc.add(uid)
            status = 'pass'
            if tc.find('failure') is not None:
                status = 'failure'
            elif tc.find('error') is not None:
                status = 'error'
            elif tc.find('skipped') is not None:
                status = 'skipped'
            raw_f = tc.get('file') or ts.get('file') or ''
            f = norm_file(raw_f, tc.get('class'), ts.get('name'))
            name = tc.get('name') or '?'
            pseudo = ''
            if raw_f and 'tests/' not in raw_f:
                m2 = re.match(r'^(.*?)\s*\([^)]*\)\s*$', raw_f.split('::')[0])
                if m2:
                    pseudo = m2.group(1)
            tests.append({'file': f, 'dir': suite_dir(f), 'name': name,
                          'pseudo': slug(pseudo) if pseudo else None,
                          'key': junit_key(name),
                          'assertions': int(tc.get('assertions') or 0),
                          'ms': round(float(tc.get('time') or 0) * 1000.0, 1),
                          'status': status})

# ── parse profile csv ───────────────────────────────────────────────────────
# The CSV file column points at the eval'd TestCaseFactory, not the real test
# file — join on KEY only, disambiguated by duration when multiple files share
# a description.
csv_rows = []
csv_path = os.path.join(R, 'profile-seq.csv')
if os.path.isfile(csv_path):
    with open(csv_path, encoding='utf-8') as fh:
        for row in csv.DictReader(fh):
            csv_rows.append((csv_norm(row['test']), row))
else:
    print('analyze-test-profile: no profile-seq.csv — query/memory columns stay empty', file=sys.stderr)

exact = {}
for norm, row in csv_rows:
    exact.setdefault(norm, []).append(row)


def junit_probe(t):
    g, d = t['key']
    if g:
        return f'{g}_{d}'
    if t.get('pseudo'):
        return f"{t['pseudo']}_{d}"
    return d


def pick(rows, ms_ref):
    if len(rows) == 1:
        return rows[0]
    return min(rows, key=lambda r: abs(float(r['duration_ms']) - ms_ref))


for t in tests:
    probe = junit_probe(t)
    rows = exact.get(probe)
    if rows is None:
        rows = [r for norm, r in csv_rows if norm.endswith(probe) or norm.endswith('_' + probe)]
    if not rows:
        t['queries'] = t['mem_kb'] = None
        continue
    hit = pick(rows, t['ms'])
    t['queries'] = int(hit['queries'])
    t['mem_kb'] = int(hit['mem_delta_kb'])

n = len(tests)
total_ms = sum(t['ms'] for t in tests)
total_assert = sum(t['assertions'] for t in tests)
statuses = {}
for t in tests:
    statuses[t['status']] = statuses.get(t['status'], 0) + 1
joined = sum(1 for t in tests if t['queries'] is not None)
times = sorted(t['ms'] for t in tests)


def quantile(q):
    if not times:
        return 0.0
    k = (len(times) - 1) * q
    f_, c = int(k), min(int(k) + 1, len(times) - 1)
    return times[f_] + (times[c] - times[f_]) * (k - f_)


ordered = sorted(tests, key=lambda t: -t['ms'])
pareto = {}
cum = 0.0
for i, t in enumerate(ordered, 1):
    cum += t['ms']
    if i in (50, 100, 250):
        pareto[i] = cum
top1 = ordered[:max(1, int(n * 0.01))]
top5 = ordered[:max(1, int(n * 0.05))]
top10 = ordered[:max(1, int(n * 0.10))]


def agg(rows):
    s = sum(r['ms'] for r in rows)
    return {'tests': len(rows), 'ms': s, 'avg_ms': s / len(rows) if rows else 0,
            'max_ms': max((r['ms'] for r in rows), default=0),
            'assertions': sum(r['assertions'] for r in rows),
            'queries': sum(r['queries'] for r in rows if r['queries'] is not None),
            'queries_joined': sum(1 for r in rows if r['queries'] is not None)}


by_file, by_dir = {}, {}
for t in tests:
    by_file.setdefault(t['file'], []).append(t)
    by_dir.setdefault(t['dir'], []).append(t)
file_stats = sorted(((f, agg(rs)) for f, rs in by_file.items()), key=lambda x: -x[1]['ms'])
dir_stats = sorted(((d, agg(rs)) for d, rs in by_dir.items()), key=lambda x: -x[1]['ms'])

# dataset fan-out on junit names
ds = {}
for t in tests:
    base = re.sub(r'\s+with data set\s+.*$', '', t['name'])
    ds.setdefault((t['file'], base), []).append(t)
datasets = sorted(((f, b, len(rs), sum(r['ms'] for r in rs), max(r['ms'] for r in rs))
                   for (f, b), rs in ds.items() if len(rs) > 1), key=lambda x: -x[3])

# smoke subset
smoke_ms = smoke_n = 0
sf_path = os.path.join(R, 'smoke-files.txt')
if os.path.isfile(sf_path):
    with open(sf_path, encoding='utf-8') as fh:
        smoke_files = {'tests/' + line.strip().split('tests/')[-1] for line in fh if line.strip()}
    for f, s in file_stats:
        if f in smoke_files:
            smoke_ms += s['ms']
            smoke_n += s['tests']

# parallel totals
par_n = par_ms = par_assert = 0
par_path = os.path.join(R, 'junit-par.xml')
if os.path.isfile(par_path):
    for tc in ET.parse(par_path).getroot().iter('testcase'):
        par_n += 1
        par_ms += float(tc.get('time') or 0) * 1000.0
        par_assert += int(tc.get('assertions') or 0)

# runner meta (chunk wall times, cold floor, smoke count, commit)
meta = {'chunks': {}}
rl_path = os.path.join(R, 'runner.log')
if os.path.isfile(rl_path):
    with open(rl_path, encoding='utf-8') as fh:
        for line in fh:
            m = re.match(r'^chunk_(\S+) seconds=(\d+) rc=(\d+)', line.strip())
            if m:
                meta['chunks'][m.group(1)] = (int(m.group(2)), int(m.group(3)))
                continue
            m = re.match(r'^cold_single_seconds=(\d+)', line.strip())
            if m:
                meta['cold'] = int(m.group(1))
                continue
            m = re.match(r'^smoke_files=(\d+)', line.strip())
            if m:
                meta['smoke_files'] = int(m.group(1))
                continue
            m = re.match(r'^commit=(\S+)', line.strip())
            if m:
                meta['commit'] = m.group(1)
seq_wall = sum(v[0] for k, v in meta['chunks'].items() if k != 'par')
par_wall = meta['chunks'].get('par', (0, 0))[0]

with open(os.path.join(OUT, 'per-dir.csv'), 'w', newline='', encoding='utf-8') as fh:
    w = csv.writer(fh)
    w.writerow(['dir', 'tests', 'total_ms', 'pct_of_suite', 'avg_ms', 'max_ms', 'assertions', 'avg_queries'])
    for d, s in dir_stats:
        w.writerow([d, s['tests'], round(s['ms']), pct(s['ms'], total_ms), round(s['avg_ms']),
                    round(s['max_ms']), s['assertions'],
                    round(s['queries'] / s['queries_joined'], 1) if s['queries_joined'] else ''])

with open(os.path.join(OUT, 'per-file.csv'), 'w', newline='', encoding='utf-8') as fh:
    w = csv.writer(fh)
    w.writerow(['file', 'dir', 'tests', 'total_ms', 'pct_of_suite', 'avg_ms', 'max_ms', 'assertions'])
    for f, s in file_stats:
        w.writerow([f, by_file[f][0]['dir'], s['tests'], round(s['ms']), pct(s['ms'], total_ms),
                    round(s['avg_ms']), round(s['max_ms']), s['assertions']])

with open(os.path.join(OUT, 'per-test.csv'), 'w', newline='', encoding='utf-8') as fh:
    w = csv.writer(fh)
    w.writerow(['file', 'test', 'ms', 'assertions', 'queries', 'mem_delta_kb', 'status'])
    for t in ordered:
        w.writerow([t['file'], t['name'], t['ms'], t['assertions'],
                    t['queries'] if t['queries'] is not None else '',
                    t['mem_kb'] if t['mem_kb'] is not None else '', t['status']])

with open(os.path.join(OUT, 'datasets.csv'), 'w', newline='', encoding='utf-8') as fh:
    w = csv.writer(fh)
    w.writerow(['file', 'base_test', 'cases', 'total_ms', 'worst_case_ms'])
    for row in datasets:
        w.writerow(row)

md = []
md.append(f'# Measurement digest — commit {meta.get("commit", "?")[:12]}')
md.append('')
md.append(f'- tests: {n} (failures={statuses.get("failure", 0)}, errors={statuses.get("error", 0)}, skipped={statuses.get("skipped", 0)})')
md.append(f'- suite test-time (sequential, sum of per-test): {sec(total_ms)}')
md.append(f'- assertions: {total_assert}')
md.append(f'- profiler join coverage: {joined}/{n} tests ({pct(joined, n)})')
md.append(f'- runner wall: cold_single={meta.get("cold", "?")}s seq_chunks={seq_wall}s par={par_wall}s')
md.append('')
md.append('## Per-directory (sorted by total time)')
md.append('| dir | tests | total | % suite | avg ms | max ms | assertions | avg queries/test |')
md.append('|---|---:|---:|---:|---:|---:|---:|---:|')
for d, s in dir_stats:
    aq = f"{s['queries'] / s['queries_joined']:.0f}" if s['queries_joined'] else '—'
    md.append(f"| {d} | {s['tests']} | {sec(s['ms'])} | {pct(s['ms'], total_ms)} | {ms(s['avg_ms'])} | {ms(s['max_ms'])} | {s['assertions']} | {aq} |")
md.append('')
md.append('## Slowest files (top 40)')
md.append('| # | file | tests | total | % suite | avg ms | max ms |')
md.append('|---:|---|---:|---:|---:|---:|---:|')
for i, (f, s) in enumerate(file_stats[:40], 1):
    md.append(f'| {i} | `{f}` | {s["tests"]} | {sec(s["ms"])} | {pct(s["ms"], total_ms)} | {ms(s["avg_ms"])} | {ms(s["max_ms"])} |')
md.append('')
md.append('## Slowest individual tests (top 60)')
md.append('| # | ms | test | file | assert | queries |')
md.append('|---:|---:|---|---|---:|---:|')
for i, t in enumerate(ordered[:60], 1):
    q = t['queries'] if t['queries'] is not None else '—'
    md.append(f'| {i} | {ms(t["ms"])} | {t["name"][:90]} | `{t["file"]}` | {t["assertions"]} | {q} |')
md.append('')
md.append('## Query-heaviest tests (top 40)')
qt = sorted((t for t in tests if t['queries'] is not None), key=lambda t: -(t['queries'] or 0))
md.append('| # | queries | ms | test | file |')
md.append('|---:|---:|---:|---|---|')
for i, t in enumerate(qt[:40], 1):
    md.append(f'| {i} | {t["queries"]} | {ms(t["ms"])} | {t["name"][:90]} | `{t["file"]}` |')
md.append('')
md.append('## Memory-delta heaviest tests (top 30)')
mt = sorted((t for t in tests if t['mem_kb'] is not None), key=lambda t: -(t['mem_kb'] or 0))
md.append('| # | dMB | ms | test | file |')
md.append('|---:|---:|---:|---|---|')
for i, t in enumerate(mt[:30], 1):
    md.append(f'| {i} | {t["mem_kb"] / 1024.0:.1f} | {ms(t["ms"])} | {t["name"][:90]} | `{t["file"]}` |')
md.append('')
md.append('## Dataset fan-out (top 30 by cumulative time)')
md.append('| # | cases | total | worst case | test | file |')
md.append('|---:|---:|---:|---:|---|---|')
for i, (f, b, cases, tot, worst) in enumerate(datasets[:30], 1):
    md.append(f'| {i} | {cases} | {sec(tot)} | {ms(worst)} | {b[:80]} | `{f}` |')
md.append('')
md.append('## Distribution & concentration')
md.append(f'- per-test ms: p50={ms(quantile(0.50))} p75={ms(quantile(0.75))} p90={ms(quantile(0.90))} p95={ms(quantile(0.95))} p99={ms(quantile(0.99))} max={ms(times[-1] if times else 0)}')
md.append(f'- mean per test: {ms(total_ms / n if n else 0)} ms')
md.append(f'- top 1% of tests ({len(top1)}) consume {sec(sum(t["ms"] for t in top1))} ({pct(sum(t["ms"] for t in top1), total_ms)})')
md.append(f'- top 5% ({len(top5)}) consume {sec(sum(t["ms"] for t in top5))} ({pct(sum(t["ms"] for t in top5), total_ms)})')
md.append(f'- top 10% ({len(top10)}) consume {sec(sum(t["ms"] for t in top10))} ({pct(sum(t["ms"] for t in top10), total_ms)})')
for k, v in pareto.items():
    md.append(f'- top {k} tests cumulative: {sec(v)} ({pct(v, total_ms)})')
md.append('')
md.append('## Smoke subset')
md.append(f'- smoke files: {meta.get("smoke_files", "?")} — {smoke_n} tests, {sec(smoke_ms)} suite-time ({pct(smoke_ms, total_ms)} of suite)')
md.append('')
md.append('## Real-time wait suspects (ms >= 1500, queries <= 30)')
suspects = [t for t in tests if t['ms'] >= 1500 and (t['queries'] is not None and t['queries'] <= 30)]
suspects.sort(key=lambda t: -t['ms'])
md.append(f'- {len(suspects)} tests, {sec(sum(t["ms"] for t in suspects))} cumulative')
md.append('| ms | queries | test | file |')
md.append('|---:|---:|---|---|')
for t in suspects[:25]:
    md.append(f'| {ms(t["ms"])} | {t["queries"]} | {t["name"][:80]} | `{t["file"]}` |')
md.append('')
total_q = sum(t['queries'] for t in tests if t['queries'] is not None)
md.append(f'- joined-subset total queries: {total_q:,} across {joined} tests')
md.append('')
md.append('## Parallel comparison')
md.append(f'- parallel junit: {par_n} tests, {sec(par_ms)} summed test-time, {par_assert} assertions')
if par_n:
    md.append(f'- parallel/sequential test-count delta: {par_n - n:+d}')
with open(os.path.join(OUT, 'report-data.md'), 'w', encoding='utf-8') as fh:
    fh.write('\n'.join(md) + '\n')

print(f'tests={n} suite_time={sec(total_ms)} asserts={total_assert} join={joined}/{n} ({pct(joined, n)})')
print(f'wall: cold={meta.get("cold")}s seq_chunks={seq_wall}s par={par_wall}s')
print(f'statuses={statuses}')
print(f'p50/p90/p99/max ms = {ms(quantile(0.5))}/{ms(quantile(0.9))}/{ms(quantile(0.99))}/{ms(times[-1] if times else 0)}')
print('top dirs: ' + ', '.join(f'{d}={sec(s["ms"])}' for d, s in dir_stats[:8]))
print(f'wrote {OUT}/report-data.md + 4 csvs')
