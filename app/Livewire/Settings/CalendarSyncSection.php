<?php

namespace App\Livewire\Settings;

use App\Jobs\DisconnectGoogleCalendarConnection;
use App\Models\GoogleCalendarConnection;
use App\Services\Calendar\Google\GoogleCalendarAuth;
use App\Support\CalendarSyncGate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Google Calendar managed-sync section of the Settings surface
 * (M083/S02-T03, D287) — mounted in the account tab next to the iCal
 * feed card.
 *
 * The truth-telling surface for the connection lifecycle: the current
 * status (connected/revoked, linked Google account, last sync), the
 * per-source toggle rows (games/campaign_sessions/events — the sources
 * S01's consent copy documents), disconnect, and the revoked re-auth
 * banner. All copy comes from lang/calendar_sync.php — the blade holds
 * zero literals.
 *
 * The connect CTA routes to the S02-T02 OAuth round trip
 * (settings.calendar.google.connect) when CalendarSyncGate is open;
 * while the gate is closed the CTA renders inert with the honest
 * refusal copy (error_connect_refused_disabled /
 * error_connect_refused_not_allowlisted) instead of a route hop, and
 * the gate-refusal flash the controller sets on a refused hop renders
 * here too. The limited-rollout badge (label_mode_test_user_note)
 * accompanies the connect CTA and the status row while
 * verification_mode=test_user (D300).
 *
 * State strategy: the connection is a Computed read fresh from the
 * database on every render (the OAuth callback redirects back with a
 * full page load, so mount re-seeds after every connect/disconnect
 * transition), while $sourceToggles is the seeded public state the
 * switch rows render — the same split Show keeps between mount-loaded
 * preferences and rendered controls.
 *
 * @property ?GoogleCalendarConnection $connection
 */
class CalendarSyncSection extends Component
{
    /**
     * Per-source sync toggles seeded from the connection row (or the
     * all-true defaults when unconnected). The switch rows render this;
     * toggleSource() flips one key and persists the whole shape through
     * the model's canonical accessor. The canonical {source: bool}
     * shape over SOURCE_TOGGLES is enforced by the model's accessor on
     * every write — this property only ever holds exactly those keys
     * once mount() has seeded it (the bare [] default exists for the
     * pre-mount window Livewire hydrates public properties through).
     *
     * @var array<string, bool>
     */
    public array $sourceToggles = [];

    /**
     * Whether the disconnect confirmation is open: the user chooses what
     * happens to the Roundup-managed events — keep them (default) or
     * remove them all before the grant is revoked. Never a silent
     * either/or: the choice is explicit and pre-selected to keep.
     */
    public bool $disconnectDialogOpen = false;

    /**
     * The chosen disconnect disposition: 'keep' (default — events stay,
     * roundup just stops managing them) or 'remove' (every marked event
     * is deleted from the Google Calendar first, then the grant dies).
     */
    public string $disconnectDisposition = 'keep';

    public function mount(): void
    {
        $connection = $this->connection();

        $this->sourceToggles = $connection !== null
            ? $connection->source_toggles
            : $this->defaultToggles();
    }

    /**
     * Toggle one sync source on the user's connection. The source name
     * is validated against GoogleCalendarConnection::SOURCE_TOGGLES —
     * an unknown name (only reachable through a tampered payload) is
     * ignored, and the model's canonical accessor enforces the full
     * {source: bool} shape on write.
     */
    public function toggleSource(string $source): void
    {
        if (! in_array($source, GoogleCalendarConnection::SOURCE_TOGGLES, true)) {
            return;
        }

        $connection = $this->connection();

        if ($connection === null) {
            return;
        }

        $toggles = $connection->source_toggles;
        $toggles[$source] = ! $toggles[$source];

        $connection->update(['source_toggles' => $toggles]);

        $this->sourceToggles = $toggles;

        unset($this->connection);
    }

    /**
     * Open the disconnect confirmation with keep pre-selected — the
     * disposition is the user's explicit choice, never assumed.
     */
    public function disconnect(): void
    {
        $this->disconnectDisposition = 'keep';

        $this->disconnectDialogOpen = true;
    }

    public function cancelDisconnect(): void
    {
        $this->disconnectDialogOpen = false;
    }

    /**
     * Execute the chosen disconnect.
     *
     * Keep: revoke the grant at Google (best-effort — the row's fate is
     * decided here), then delete the connection row either way. A revoke
     * that fails at Google must not leave a connection the user asked to
     * remove; the row IS the roundup-side credential store. The kept
     * events simply stop being managed — they stay exactly as they are.
     *
     * Remove: mark the row disconnecting (the honest UI state and the
     * job's race guard) and hand off to the queued purge — deletion
     * MUST run while the grant is still alive, so it can never happen
     * inline after a revoke. A dispatch failure reverts the row to
     * active (nothing was promised, nothing was changed) and surfaces
     * as the structured dispatch-failure line.
     */
    public function confirmDisconnect(): void
    {
        $connection = $this->connection();

        if ($connection === null) {
            $this->disconnectDialogOpen = false;

            return;
        }

        if ($this->disconnectDisposition === 'remove') {
            $connection->update(['status' => GoogleCalendarConnection::STATUS_DISCONNECTING]);

            try {
                DisconnectGoogleCalendarConnection::dispatch($connection->id);
            } catch (Throwable $e) {
                // Nothing was promised and nothing was purged — revert to
                // the live state so the user can simply try again.
                $connection->update(['status' => GoogleCalendarConnection::STATUS_ACTIVE]);

                Log::error('calendar_sync.disconnect_dispatch_failed', [
                    'user_id' => $connection->user_id,
                    'connection_id' => $connection->id,
                    'exception' => $e->getMessage(),
                    'exception_class' => $e::class,
                ]);
            }

            $this->disconnectDialogOpen = false;

            unset($this->connection);

            return;
        }

        app(GoogleCalendarAuth::class)->revoke($connection);

        $connection->delete();

        $this->disconnectDialogOpen = false;

        $this->sourceToggles = $this->defaultToggles();

        unset($this->connection);
    }

    /**
     * The user's current Google calendar connection, or null when
     * unconnected. Read fresh per render so every lifecycle transition
     * (disconnect here, connect via the OAuth redirect reload) is
     * reflected without state plumbing.
     */
    #[Computed]
    public function connection(): ?GoogleCalendarConnection
    {
        return authenticatedUser()->googleCalendarConnection()->first();
    }

    /**
     * May the connect CTA route to Google under the deployment's
     * current gate posture (CalendarSyncGate, D300)?
     */
    #[Computed]
    public function connectAllowed(): bool
    {
        return CalendarSyncGate::supportsConnect(authenticatedUser());
    }

    /**
     * The honest-refusal reason rendering the connect CTA inert while
     * the gate is closed: 'disabled' (not enabled for this deployment)
     * or 'not_allowlisted' (enabled but the email is outside the
     * test-user allowlist). Mirrors CalendarLinkController::refusalReason
     * — same two-key disambiguation from the deployment config.
     */
    #[Computed]
    public function refusalReason(): ?string
    {
        if ($this->connectAllowed()) {
            return null;
        }

        return (bool) config('calendar_sync.enabled') ? 'not_allowlisted' : 'disabled';
    }

    /**
     * Whether the limited-rollout badge (label_mode_test_user_note)
     * accompanies the connect CTA and status row — true while
     * verification_mode=test_user (D300's build/verify posture).
     */
    #[Computed]
    public function testModeActive(): bool
    {
        return config('calendar_sync.verification_mode') === 'test_user';
    }

    /**
     * @return array{games: bool, campaign_sessions: bool, events: bool}
     */
    private function defaultToggles(): array
    {
        return array_fill_keys(GoogleCalendarConnection::SOURCE_TOGGLES, true);
    }

    public function render(): View
    {
        return view('livewire.settings.calendar-sync-section');
    }
}
