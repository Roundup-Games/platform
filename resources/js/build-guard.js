// Deploy-transition guard (2026-11-20).
//
// Livewire's bundled Alpine plugins ($persist and friends) register through
// Alpine.magic() — a non-configurable defineProperty on the SHARED
// window.Alpine. When wire:navigate pulls a NEW build's page into a tab that
// is still running the OLD build, this bundle re-executes against the
// surviving Alpine instance and dies with:
//
//     Uncaught TypeError: can't redefine non-configurable property "$persist"
//
// …before any of app.js's own statements run (the registration lives in a
// hoisted module import). The half-boot leaves the page chrome alive while
// every Alpine/Livewire surface stays blank until a manual force-refresh —
// exactly the post-deploy "tabs render, contents don't" symptom.
//
// The guard: record this build's asset URL on the window; if a DIFFERENT
// build's bundle ever executes in the same window, hard-reload so exactly
// one consistent build boots. Must be app.js's FIRST import — module imports
// hoist, and livewire.esm's plugin registration throws before any later
// module or body code gets to run.
//
// Straggler case (seen in production 2026-11-21, /en/forgot-password): tabs
// still running a build from BEFORE this guard shipped have no marker set,
// yet carry a surviving window.Alpine from the old bundle. A pure
// marker-mismatch check waves those through and the crash still happens —
// so an Alpine WITHOUT a marker is treated as a pre-guard build and
// reloaded. A genuinely fresh load never defines window.Alpine before this
// module runs (livewire.esm sets it later in this same bundle's import
// order), so this cannot false-positive.
const buildUrl = new URL(import.meta.url).pathname;

if (window.Alpine !== undefined && window.__roundupBuild === undefined) {
    window.location.reload();
    throw new Error(
        'Roundup pre-guard Alpine detected — reloading for a consistent boot.',
    );
}

if (window.__roundupBuild !== undefined && window.__roundupBuild !== buildUrl) {
    window.location.reload();
    throw new Error(
        `Roundup build changed (${window.__roundupBuild} → ${buildUrl}) — reloading for a consistent boot.`,
    );
}

window.__roundupBuild = buildUrl;
