<?php

namespace App\Services;

use Escalated\Laravel\Contracts\EscalatedUiRenderer;
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
     * @param  array<string, mixed>  $props
     */
    public function render(string $page, array $props = []): Response
    {
        $viewName = self::VIEW_MAP[$page] ?? null;

        if ($viewName === null || ! view()->exists($viewName)) {
            abort(404, 'Support page not found.');
        }

        return response()->view($viewName, $props);
    }
}
