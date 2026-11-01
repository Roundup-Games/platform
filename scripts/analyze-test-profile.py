#!/usr/bin/env python3
"""Analyze the test-harness profiling datasets (junit + profile CSV).

Inputs (sequential run, 2026-10-31):
  .gsd/research/test-junit-2026-10-31.xml   — per-test time + assertions + class
  .gsd/research/test-profile-2026-10-31.csv — per-test queries + mem delta (Feature only)

The two streams are not perfectly joinable (CSV covers only the Feature
extension; junit covers everything), so each contributes what it measures
best: junit -> time/assertion/file rankings; CSV -> query and memory
rankings by test name.
"""
import csv
import xml.etree.ElementTree as ET
from collections import defaultdict
from pathlib import Path

RESEARCH = Path(".gsd/research")
JUNIT = RESEARCH / "test-junit-2026-10-31.xml"
CSVF = RESEARCH / "test-profile-2026-10-31.csv"


def pct(sorted_vals, p):
    if not sorted_vals:
        return 0
    k = max(0, min(len(sorted_vals) - 1, int(round(p / 100 * (len(sorted_vals) - 1)))))
    return sorted_vals[k]


def main():
    root = ET.parse(JUNIT).getroot()
    tests = []
    for tc in root.iter("testcase"):
        klass = tc.get("classname", "")
        # Pest v5 junit writes classnames dot-separated (Tests.Feature.X.Y).
        parts = klass.replace("\\", ".").split(".")
        rel = ("tests/" + "/".join(parts[1:]) + ".php") if parts and parts[0] == "Tests" else ""
        tests.append({
            "class": klass,
            "name": tc.get("name", ""),
            "time": float(tc.get("time", "0")),
            "assertions": int(tc.get("assertions", "0") or 0),
            "file": rel,
            "failed": tc.find("failure") is not None or tc.find("error") is not None,
        })

    rows = []
    with open(CSVF) as f:
        for r in csv.DictReader(f):
            rows.append(r)

    def relpath(t):
        return (t["file"] or t["class"]).replace(str(Path.cwd()) + "/", "")

    out = []
    w = out.append

    total = sum(t["time"] for t in tests)
    total_asserts = sum(t["assertions"] for t in tests)
    times = sorted(t["time"] for t in tests)
    fails = [t for t in tests if t["failed"]]

    w("## Totals")
    w(f"- tests: {len(tests)}  |  failed: {len(fails)}")
    w(f"- total sequential test time: {total:.0f}s ({total/60:.1f} min)")
    w(f"- total assertions: {total_asserts:,}")
    w(f"- distribution (s): p50={pct(times,50):.3f}  p90={pct(times,90):.3f}  p99={pct(times,99):.3f}  max={times[-1]:.3f}")
    w("")

    w("## Top 30 slowest tests")
    for t in sorted(tests, key=lambda x: -x["time"])[:30]:
        w(f"- {t['time']:7.3f}s  a={t['assertions']:<4} {relpath(t)}::{t['name'][:85]}")
    w("")

    by_file = defaultdict(lambda: {"time": 0.0, "n": 0, "asserts": 0})
    for t in tests:
        e = by_file[t["file"] or t["class"]]
        e["time"] += t["time"]
        e["n"] += 1
        e["asserts"] += t["assertions"]

    w("## Top 25 slowest files (cumulative)")
    for k, e in sorted(by_file.items(), key=lambda kv: -kv[1]["time"])[:25]:
        share = 100 * e["time"] / total if total else 0
        w(f"- {e['time']:7.1f}s ({share:4.1f}%)  {e['n']:>4} tests  {e['asserts']:>5} asserts  {k.replace(str(Path.cwd()) + '/', '')}")
    w("")

    by_dir = defaultdict(lambda: {"time": 0.0, "n": 0, "asserts": 0})
    for t in tests:
        f = (t["file"] or "").replace(str(Path.cwd()) + "/", "")
        d = "/".join(f.split("/")[:3]) if f else "(no file)"
        e = by_dir[d]
        e["time"] += t["time"]
        e["n"] += 1
        e["asserts"] += t["assertions"]

    w("## Directory rollup")
    for k, e in sorted(by_dir.items(), key=lambda kv: -kv[1]["time"]):
        share = 100 * e["time"] / total if total else 0
        w(f"- {e['time']:8.1f}s ({share:4.1f}%)  {e['n']:>5} tests  {e['asserts']:>7} asserts  {k}")
    w("")

    w("## Top 20 query-heaviest tests (N+1 suspects) [csv]")
    for r in sorted(rows, key=lambda r: -int(r["queries"] or 0))[:20]:
        w(f"- q={int(r['queries']):>6}  {r['duration_ms']:>8}ms  {r['test'][:95]}")
    w("")

    qvals = sorted(int(r["queries"] or 0) for r in rows)
    if qvals:
        w(f"- queries/test: p50={pct(qvals,50)}  p90={pct(qvals,90)}  p99={pct(qvals,99)}  max={qvals[-1]}  total={sum(qvals):,}")
        w("")

    w("## Top 15 memory-heaviest tests (allocation delta KB) [csv]")
    for r in sorted(rows, key=lambda r: -int(r["mem_delta_kb"] or 0))[:15]:
        w(f"- {int(r['mem_delta_kb']):>7}KB  {r['test'][:95]}")
    w("")

    w("## Worst cost-per-assertion (>=1s tests, few assertions)")
    cand = [t for t in tests if t["time"] >= 1.0 and t["assertions"] <= 5]
    for t in sorted(cand, key=lambda x: -(x["time"] / max(1, x["assertions"])))[:20]:
        w(f"- {t['time']/max(1,t['assertions']):6.2f}s/assert  ({t['time']:5.2f}s, a={t['assertions']}) {relpath(t)}::{t['name'][:75]}")
    w("")

    st = sorted((t["time"] for t in tests), reverse=True)
    for frac in (0.05, 0.10, 0.25):
        k = max(1, int(len(st) * frac))
        share = 100 * sum(st[:k]) / total if total else 0
        w(f"- top {frac*100:.0f}% of tests ({k}) consume {share:.1f}% of runtime")
    w("")

    Path(".gsd/research/test-analysis-2026-10-31.txt").write_text("\n".join(out))
    print(f"junit tests: {len(tests)}   csv rows: {len(rows)}")
    print(f"[full report: .gsd/research/test-analysis-2026-10-31.txt]")


if __name__ == "__main__":
    main()
