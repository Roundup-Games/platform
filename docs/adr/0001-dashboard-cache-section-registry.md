# 0001 — Dashboard cache: section registry, assemble() view-model, phased migration

## Context

`DashboardCacheService` (1,867 LOC, 38 public methods) fuses four responsibilities — cache lifecycle (three-tier read + stampede protection + background warm), invalidation (two bulk methods with 11-branch `if (in_array('section'))` chains plus six event-specific helpers), four deep compute methods squatting in the cache module (`computeWeekData` ~111 LOC, `computeOpportunities`, `computeContributions`, `computeFeedData`), and ~7 delegate callbacks that create a circular dependency with the sibling services they resolve. `Livewire\Dashboard::render()` orchestrates ~8 services and hand-builds a 26-key view dictionary with stub-prop symmetry (each mode branch pads the other mode's keys with empty values). See `.gsd/test-harness-review-2026-06.md` and the validation at `/tmp/validate-dashboard-sprawl.md`.

## Decision

Deepen the **Dashboard cache** module in three phases, behind an interface with:

- A `DashboardSection` configuration object (one per section) owning its cache-key construction, TTL, and lock policy — so key-construction rules stop being duplicated across two invalidation chains.
- A `remember(Section, User)` three-tier read primitive (the only read entry point).
- An `assemble(User): AssembledDashboard` view-model entry point for the common caller (`render()`), with the inactive mode's wing null rather than stub-padded.
- Computers moved to sibling services; the cache module invokes them via the registry (taking callables, not `app(Sibling)`), severing the circular dependency.

Migration is phased and behaviour-preserving:

- **Phase 1 (this change):** Introduce `DashboardAssembler` + the typed view-model DTOs. `render()` collapses to one call. `assemble()` projects the existing getters into an `AssembledDashboard`; `toViewProps()` is a legacy bridge emitting the exact pre-refactor 26-key dictionary so Blade, observers, and the warm job are untouched.
- **Phase 2:** Introduce `DashboardSection` descriptors + registry; rewrite each getter as `remember($section, $user)`; move the four deep computes to siblings; collapse the invalidation chains to registry loops; sever the circular DI.
- **Phase 3 (this change, three hops):** Full view-contract modernization. The Livewire component passes a single typed `AssembledDashboard` view-model as `$dashboard`; the wrapper branches on it and unpacks the active mode's wing into the flat variables each partial already consumes; `toViewProps()` and the 16 inactive-mode stub keys are deleted. The Dashboard test suite asserts on the typed DTO (`viewData('dashboard')->shared->...`, `->newcomer->...`, `->established->...`) rather than the flat view-variable contract. Three IntegrationTest cases that previously read established-only sections off a newcomer render (passing only via stub values) were corrected to test their actual intent. Partials stay untouched (lowest blast radius); all unpacking lives in the wrapper.

## Rejected alternatives

- **Enum-keyed minimize interface (3 entry points + 9 invalidation event classes).** Deepest by entry-point count, but requires rewriting all five observers to an event taxonomy in the same pass as the registry — too much churn in one change for a stability-first mandate. The event-invalidation shape may be adopted as a later phase once the registry internals are proven.
- **Full registry/declaration framework (one class per section, 8-method `DashboardSection` interface).** Maximises extension locality (adding a section is one file + one registration line), but the 8-method-per-section weight is over-engineered for 12 slow-changing sections. Phase 2 will use a lighter readonly descriptor class, capturing most of this locality at lower weight.
- **Big-bang collapse into a single Dashboard module.** Rejected by validation: five of the six sibling services (`DashboardModeService`, `DashboardDiscoveryService`, `DashboardScheduleService`, `DashboardQuickActionsService`, `DashboardSmartPromptService`) are genuinely deep and pass the deletion test — collapsing them loses depth.

## Consequences

- Cache keys may change once during Phase 2 (a one-time flush on deploy is acceptable); no external consumers exist (verified — `DemoTeardownCommand` already has a stale key-format bug proving this: it clears `dashboard:{userId}:{section}` while the canonical format is `dashboard:{section}:{userId}`, so its teardown silently no-ops on dashboard keys, and no one has reported a problem).
- The view-model couples the assembler to the view contract — the correct coupling, made explicit (the DTO *is* the view's data contract). `toViewProps()` makes this a Phase-1 no-op for Blade and a Phase-3 deletion target.
- Per-section failure isolation becomes possible in Phase 2 (a throwing section degrades to its empty default instead of blanking the Dashboard) — a reliability gain, not a refactor side-effect.
