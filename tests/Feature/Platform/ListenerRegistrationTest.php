<?php

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\App;

//
// Single-registration invariant for App listeners: a listener with a
// handle(EventType) signature is registered by event auto-discovery
// (EventServiceProvider::$shouldDiscoverEvents) — an ADDITIONAL explicit
// EventFacade::listen() registration makes every event run the listener
// twice (double notifications, double log lines, double analytics). That
// defect class shipped twice before being caught (commit 18c0cf96 removed
// the ticket-listener pair; a later review found five more neighbors), so
// the invariant is now machine-pinned: every discovered listener must
// appear EXACTLY once per event in the live dispatcher, and the one
// array-callable listener (RecordScheduledTaskRun — method-per-event, no
// handle(), invisible to discovery) must appear exactly once per
// scheduled event.
//
// Shared Postgres testcontainer: bootstrap migrates once, transactions
// isolate — no RefreshDatabase.
//

/**
 * Raw registration targets per event from the live dispatcher: closure
 * registrations carry the target in static vars (bare class string for
 * explicit listen(), class@method for discovery); plain strings pass
 * through unchanged.
 *
 * @return array<class-string, list<string>>
 */
function rawListenerTargets(): array
{
    /** @var Dispatcher $dispatcher */
    $dispatcher = App::make(Dispatcher::class);

    /** @var array<class-string, list<mixed>> $raw */
    $raw = $dispatcher->getRawListeners();

    return collect($raw)->map(fn ($targets) => collect($targets)
        ->map(function ($target): string {
            if ($target instanceof Closure) {
                $static = (new ReflectionFunction($target))->getStaticVariables();
                $inner = $static['listener'] ?? $static['class'] ?? null;

                return is_string($inner) ? $inner : 'closure';
            }

            if (is_array($target) && isset($target[0], $target[1]) && is_string($target[0]) && is_string($target[1])) {
                return $target[0].'@'.$target[1]; // Array-callable [Class, 'method'].
            }

            return is_string($target) ? $target : 'other';
        })
        ->values()
        ->all())->all();
}

it('registers every handle()-based App listener exactly once per event — auto-discovery is the only wiring', function () {
    $rawListeners = rawListenerTargets();

    $listenerClasses = collect(glob(app_path('Listeners').'/*.php'))
        ->map(fn (string $path): string => 'App\\Listeners\\'.basename($path, '.php'));

    foreach ($listenerClasses as $listenerClass) {
        $reflection = new ReflectionClass($listenerClass);

        if (! $reflection->hasMethod('handle')) {
            continue; // Method-per-event listeners — covered by the next test.
        }

        $parameters = $reflection->getMethod('handle')->getParameters();
        $type = $parameters[0]->getType() ?? null;

        // Mirror the framework's union-aware discovery
        // (Reflector::getParameterClassNames): a union-typed handle()
        // (e.g. BadgeAwarded|BadgeUpgraded) wires the listener to EVERY
        // member event — each must be registered exactly once. Skipping
        // unions here would leave precisely the notification/analytics
        // pair this invariant exists to protect uncovered.
        $events = match (true) {
            $type instanceof ReflectionUnionType => collect($type->getTypes())
                ->filter(fn ($member): bool => $member instanceof ReflectionNamedType && ! $member->isBuiltin())
                ->map(fn (ReflectionNamedType $member): string => $member->getName())
                ->all(),
            $type instanceof ReflectionNamedType && ! $type->isBuiltin() => [$type->getName()],
            default => [], // No discoverable event type-hint — discovery cannot wire it.
        };

        if ($events === []) {
            continue;
        }

        foreach ($events as $event) {
            $targets = collect($rawListeners[$event] ?? [])
                ->filter(fn (string $target): bool => str_starts_with($target, $listenerClass));

            // Failure context: the test name identifies the invariant; the
            // dump names the duplicated/missing wiring for this listener.
            expect([$listenerClass, $event, $targets->implode(', ')])->toBe([
                $listenerClass,
                $event,
                $targets->first(),
            ]);
        }
    }
});

it('registers the array-callable RecordScheduledTaskRun once per scheduled event', function () {
    $scheduledEvents = [
        ScheduledTaskStarting::class,
        ScheduledTaskFinished::class,
        ScheduledTaskFailed::class,
        ScheduledTaskSkipped::class,
        ScheduledBackgroundTaskFinished::class,
    ];

    $rawListeners = rawListenerTargets();

    foreach ($scheduledEvents as $event) {
        $targets = collect($rawListeners[$event] ?? [])
            ->filter(fn (string $target): bool => str_starts_with($target, 'App\\Listeners\\RecordScheduledTaskRun'));

        expect([$event, $targets->implode(', ')])->toBe([$event, $targets->first()]);
    }
});
