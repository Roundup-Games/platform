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
const buildUrl = new URL(import.meta.url).pathname;

if (window.__roundupBuild !== undefined && window.__roundupBuild !== buildUrl) {
    window.location.reload();
    throw new Error(
        `Roundup build changed (${window.__roundupBuild} → ${buildUrl}) — reloading for a consistent boot.`,
    );
}

window.__roundupBuild = buildUrl;
