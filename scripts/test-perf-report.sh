#!/usr/bin/env bash
#
# test-perf-report.sh — one-command test-suite performance report.
#
# Rebuilds the full harness measurement pipeline (validated 2026-11-02;
# reference bundle: storage/app/test-report/2026-11-02/):
#
#   cold    one small Unit file on a fresh Testcontainers PostgreSQL — the
#           fixed per-run overhead floor (harness cost, not suite cost)
#   unit    tests/Unit            sequential, per-test profiling ON
#   arch    tests/Arch            sequential, per-test profiling ON
#   q1      tests/Feature/[A-D]*  sequential, per-test profiling ON
#   q2      tests/Feature/[E-L]*  sequential, per-test profiling ON
#   q3      tests/Feature/[M-R]*  sequential, per-test profiling ON
#   q4      tests/Feature/[S-Z]*  sequential, per-test profiling ON
#          (letter-range chunks keep every pest invocation inside tool/CI
#           timeouts; per-chunk --log-junit files are the authoritative data)
#   par     full suite --parallel, timed — wall-clock comparison
#   analyze merge junit chunks + regenerate analysis tables + refresh the
#           fixed-path mirror storage/app/test-report/LATEST-report-data.md
#
# Artifacts land in storage/app/test-report/<date>/ (gitignored). Sequential
# chunks append per-test profiler rows to profile-seq.csv (TEST_PROFILE=1 —
# hook in tests/Pest.php) and write per-chunk junit; the parallel run writes
# junit-par.xml without profiling.
#
# Runtime: ~50 min sequential + ~15 min parallel at the 2026-11-02 baseline
# (7345 tests). Launch it in the background and poll the runner log:
#
#     nohup bash scripts/test-perf-report.sh >/dev/null 2>&1 &
#     tail -f storage/app/test-report/$(date +%F)/runner.log
#
# Gate contract (consumed downstream): after a run the analysis digest is
# COPIED (never symlinked) to storage/app/test-report/LATEST-report-data.md —
# a fixed path, immune to date rollover.
#
set -uo pipefail

usage() {
    cat <<'USAGE'
Usage: bash scripts/test-perf-report.sh [--date <name>] [chunk ...]

Rebuild the test-suite performance report. With no chunks, runs the full
pipeline: cold unit arch q1 q2 q3 q4 par analyze. A full run clears the
report dir's measurement artifacts first (junit*, profile csv) for
clean-slate semantics; an explicit chunk list resumes/appends into the
existing dir instead.

Options:
  -h, --help    print this usage and exit 0
  --date <name> report directory name under storage/app/test-report/
                (default: today's date). Free-form names are allowed for
                re-analyzing copied bundles.

Chunks:
  cold     one small Unit file, fresh container — fixed-overhead floor
  unit     tests/Unit            sequential, profiled, per-chunk junit
  arch     tests/Arch            sequential, profiled, per-chunk junit
  q1       tests/Feature/[A-D]*  sequential, profiled, per-chunk junit
  q2       tests/Feature/[E-L]*  sequential, profiled, per-chunk junit
  q3       tests/Feature/[M-R]*  sequential, profiled, per-chunk junit
  q4       tests/Feature/[S-Z]*  sequential, profiled, per-chunk junit
  par      full suite --parallel, timed (no profiling)
  analyze  merge junit chunks, regenerate analysis/, refresh LATEST mirror

Exit status: 0 if every requested chunk succeeded, 1 if any failed
(per-chunk wall time + rc are recorded in runner.log either way).
USAGE
}

# ── args ────────────────────────────────────────────────────────────────────
DATE="$(date +%F)"
while [ $# -gt 0 ]; do
    case "$1" in
        -h|--help) usage; exit 0 ;;
        --date)
            [ $# -ge 2 ] || { echo "error: --date needs a value" >&2; exit 2; }
            DATE="$2"; shift 2 ;;
        --date=*) DATE="${1#--date=}"; shift ;;
        --) shift; break ;;
        *) break ;;
    esac
done

case "$DATE" in
    */*|*..*) echo "error: --date must be a plain directory name, got: $DATE" >&2; exit 2 ;;
esac

# remaining args are the chunk list; empty means the full pipeline
full_run=0
if [ $# -eq 0 ]; then
    set -- cold unit arch q1 q2 q3 q4 par analyze
    full_run=1
fi

for c in "$@"; do
    case "$c" in
        cold|unit|arch|q1|q2|q3|q4|par|analyze) ;;
        *) echo "error: unknown chunk: $c" >&2; usage >&2; exit 2 ;;
    esac
done

cd "$(dirname "$0")/.."

R="storage/app/test-report/$DATE"
LOG="$R/runner.log"
mkdir -p "$R"

failures=0

# Full runs start clean so stale artifacts from an aborted earlier run in the
# same date dir can never leak into the analysis (resume runs keep them).
if [ "$full_run" -eq 1 ]; then
    rm -f "$R"/junit-seq*.xml "$R/junit-par.xml" "$R/profile-seq.csv"
fi

echo "== perf-report start $(date -u +%FT%TZ) chunks=$* date=$DATE" >> "$LOG"
echo "commit=$(git rev-parse HEAD 2>/dev/null || echo unknown)" >> "$LOG"
php -v 2>/dev/null | head -1 >> "$LOG" || true

# Timed chunk runner: run_chunk <name> <log-file> <cmd...>
# Wall time + rc land in runner.log; output goes to the chunk's own log file.
run_chunk() {
    name="$1"; stem="$2"; shift 2
    start=$(date +%s)
    "$@" > "$R/$stem" 2>&1
    rc=$?
    end=$(date +%s)
    echo "chunk_$name seconds=$((end-start)) rc=$rc" >> "$LOG"
    if [ "$rc" -ne 0 ]; then
        failures=$((failures + 1))
        echo "perf-report: chunk $name FAILED rc=$rc (log: $R/$stem)" >&2
    fi
}

for chunk in "$@"; do
    case "$chunk" in
        cold)
            # Fresh-container floor: profiling OFF, output separate from chunks
            # (the analyzer reads cold_single_seconds= from runner.log).
            unset TEST_PROFILE TEST_PROFILE_FILE
            start=$(date +%s)
            vendor/bin/pest tests/Unit/Enums > "$R/cold-single.log" 2>&1
            rc=$?
            end=$(date +%s)
            echo "cold_single_seconds=$((end-start)) rc=$rc" >> "$LOG"
            if [ "$rc" -ne 0 ]; then
                failures=$((failures + 1))
                echo "perf-report: chunk cold FAILED rc=$rc (log: $R/cold-single.log)" >&2
            fi
            ;;
        unit|arch|q1|q2|q3|q4)
            case "$chunk" in
                unit) paths='tests/Unit' ;;
                arch) paths='tests/Arch' ;;
                q1)   paths='tests/Feature/[A-D]*' ;;
                q2)   paths='tests/Feature/[E-L]*' ;;
                q3)   paths='tests/Feature/[M-R]*' ;;
                q4)   paths='tests/Feature/[S-Z]*' ;;
            esac
            # Per-test profiling rides on the Feature extension (tests/Pest.php);
            # every sequential chunk process appends to one shared profile csv.
            export TEST_PROFILE=1
            export TEST_PROFILE_FILE="$PWD/$R/profile-seq.csv"
            # $paths is intentionally unquoted: the letter-range globs must expand.
            # shellcheck disable=SC2086
            run_chunk "$chunk" "run-$chunk.log" vendor/bin/pest $paths --log-junit "$R/junit-seq-$chunk.xml"
            unset TEST_PROFILE TEST_PROFILE_FILE
            ;;
        par)
            unset TEST_PROFILE TEST_PROFILE_FILE
            run_chunk par run-par.log vendor/bin/pest --parallel --log-junit "$R/junit-par.xml"
            ;;
        analyze)
            # Smoke-group membership for the subset-timing table.
            grep -rl "group('smoke')" tests --include='*.php' | sort > "$R/smoke-files.txt" || true
            echo "smoke_files=$(wc -l < "$R/smoke-files.txt" | tr -d ' ')" >> "$LOG"

            if python3 scripts/analyze-test-profile.py "$R"; then
                # Gate contract: mirror the digest to a FIXED path (a real copy,
                # never a symlink) — consumed downstream, immune to date rollover.
                if [ -s "$R/analysis/report-data.md" ]; then
                    cp "$R/analysis/report-data.md" storage/app/test-report/LATEST-report-data.md
                    echo "latest_mirror=storage/app/test-report/LATEST-report-data.md" >> "$LOG"
                else
                    failures=$((failures + 1))
                    echo "perf-report: analyzer wrote no report-data.md" >&2
                fi
            else
                failures=$((failures + 1))
                echo "perf-report: chunk analyze FAILED" >&2
            fi
            ;;
    esac
done

echo "== perf-report done $(date -u +%FT%TZ) failed=$failures" >> "$LOG"
if [ "$failures" -gt 0 ]; then
    exit 1
fi
exit 0
