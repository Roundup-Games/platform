<?php

namespace App\Services;

use Escalated\Laravel\Contracts\EscalatedUiRenderer;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * Blade-based renderer for the Escalated customer support portal.
 *
 * The escalated package ships Inertia/Vue UI by default. Our app uses
 * Livewire/Blade, so we provide this custom renderer that maps escalated
 * page identifiers to Blade views.
 */
class EscalatedBladeRenderer implements EscalatedUiRenderer
{
    /**
     * Map escalated page identifiers to Blade view names.
     *
     * @param  array<string, mixed>  $props
     */
    private const VIEW_MAP = [
        'Escalated/Customer/Index' => 'escalated.customer.index',
        'Escalated/Customer/Create' => 'escalated.customer.create',
        'Escalated/Customer/Show' => 'escalated.customer.show',
    ];

    /**
     * @param  array<array-key, mixed>  $props
     */
    public function render(string $page, array $props = []): Response
    {
        $viewName = self::VIEW_MAP[$page] ?? null;

        if ($viewName === null || ! view()->exists($viewName)) {
            abort(404, 'Support page not found.');
        }

        return response()->view($viewName, $this->adaptProps($page, $props));
    }

    /**
     * Bridge the package's Inertia-style payloads onto our model-based
     * Blade surfaces. Since escalated-laravel v1.9.16 the customer
     * show() controller passes CustomerTicketPayload::customerWeb() — an
     * allow-listed ARRAY built for the package's own Inertia/Vue UI — while
     * our escalated.customer.show view (and its TicketPayloadRenderer
     * dependency) consumes the Ticket model (status enum, isOpen(), reply
     * authorship, format dates). Re-resolve the model by reference with
     * exactly the relations the view reads; access scoping has already run
     * (ResolveTicketByReference + the controller's authorizeCustomer), so
     * this adapter only changes shape, never authorization. The allow-list
     * privacy contract is untouched server-side — the blade echoes only
     * what it always did (the payload exists to bound Inertia props, not
     * server-side templates).
     *
     * @param  array<array-key, mixed>  $props
     * @return array<array-key, mixed>
     */
    private function adaptProps(string $page, array $props): array
    {
        if ($page === 'Escalated/Customer/Show'
            && ($props['ticket'] ?? null) !== null
            && is_array($props['ticket'])) {
            $reference = $props['ticket']['reference'] ?? null;

            if (is_string($reference) && $reference !== '') {
                // The same eager loads the controller applied before
                // building the payload — non-internal replies with authors,
                // department, satisfaction rating.
                $props['ticket'] = Ticket::query()
                    ->where('reference', $reference)
                    ->with([
                        'replies' => fn ($query) => $query
                            ->where('is_internal_note', false)
                            ->with('author')
                            ->latest(),
                        'department',
                        'satisfactionRating',
                    ])
                    ->firstOrFail();
            }
        }

        return $props;
    }
}
