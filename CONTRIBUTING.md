# Contributing to Roundup Games

Thank you for your interest in contributing! This guide covers the basics.

## Quick Start

```bash
# Clone and install
composer install
npm install

# Environment setup
cp .env.example .env
php artisan key:generate

# Database (PostgreSQL 15+ required)
createdb roundup_games
php artisan migrate

# Seed with sample data
php artisan db:seed

# Frontend assets
npm run build

# Start the dev server
composer dev
```

See [README.md](README.md) for full setup instructions including Redis, optional services, and seed data.

## Development Workflow

### Branches

- `main` — stable, deployable
- Feature branches: `feature/short-description`
- Fix branches: `fix/short-description`

### Pull Requests

1. Fork the repository
2. Create a feature branch from `main`
3. Make your changes with tests
4. Run `composer smoke` to verify critical-path tests pass
5. Open a pull request against `main`

**PR expectations:**
- Clear description of what and why
- Tests for new behavior
- `composer smoke` passes
- One logical change per PR (keep it focused)

### Commit Signing (DCO)

We use the **Developer Certificate of Origin** (DCO). Every commit must include a `Signed-off-by:` line certifying you have the right to submit the contribution:

```
git commit -s
```

This automatically adds `Signed-off-by: Your Name <your@email.com>` to your commit message. By signing off, you certify:

> I developed (or have the right to submit) this contribution and agree to license it under the same license as the project (AGPL-3.0-or-later).

Commits without a `Signed-off-by` line will be rejected by CI.

## Code Style

We use [Laravel Pint](https://laravel.com/docs/pint) for PHP formatting:

```bash
./vendor/bin/pint           # Auto-fix
./vendor/bin/pint --test    # Check only
```

General conventions:
- **Service layer** — business logic lives in `app/Services/`, not in controllers or Livewire components
- **Enums** — state machines and status values use backed string enums in `app/Enums/`
- **Livewire components** — use `rules()` method for validation (not `#[Validate]` attributes)
- **Never expose Eloquent models** as public Livewire properties — use `#[Locked]` with primitive types
- **Translation keys** — use dotted key convention (`__('domain.key_name')`). See [lang/CONTRIBUTING_TRANSLATIONS.md](lang/CONTRIBUTING_TRANSLATIONS.md)

### Eloquent Best Practices

Prefer Laravel's model-aware APIs over manual ID plumbing — they are safer (correct key resolution, morph-map awareness) and read better. CI enforces the high-value subset via a grep-based check (see `scripts/check_eloquent_practices.sh`).

- **Routing** — pass the model to `route()`, not its key: `route('users.show', $user)` not `$user->id`. *Exception:* routes whose parameter is an explicit `{slug}` resolved manually in `mount()` (Team, Event, GameSystem, Location, venues) must keep passing `$model->slug` — those models intentionally keep the default `id` route key for Filament compatibility.
- **Creating related models** — create through the relationship: `$user->posts()->create($attrs)` not `Post::create(['user_id' => $user->id])`.
- **BelongsToMany / pivot** — `attach()` / `sync()` / `syncWithoutDetaching()` / `updateExistingPivot()` accept models directly: `$user->roles()->attach($role)` not `$role->id`.
- **Querying by relationship** — `whereBelongsTo($model)` (conventional FKs; pass a 2nd relation-name arg for non-conventional keys like `owner_id`, `reporter_id`) instead of `where('user_id', $model->id)`. For a model's own PK via a relation, use `whereKey($model)` instead of `where('id', $model->id)`. Bare `where('owner_id', ...)` is acceptable only where no model-aware API exists: negative comparisons (`where('owner_id', '!=', $user->id)` — `whereBelongsTo` is `=`-only) and where only a primitive id is in scope (e.g. `$userId` in anonymization jobs).
- **Comparing models** — `is()` / `isNot()` instead of `->id === ->id`.
- **Associating BelongsTo** — `$post->user()->associate($user)->save()` instead of setting the foreign key.
- **Timestamp columns** — `touch('cancelled_at')` to stamp a single timestamp to now (not `update(['cancelled_at' => now()])`).
- **Authorization** — `$this->authorize('ability', $model)` via a Policy, or `Gate::allowIf()` / `Gate::denyIf()` for inline boolean guards — not a manual `if (! $ok) abort(403)`.

## Testing

```bash
# Smoke tests (run before every commit)
composer smoke

# Full suite
composer test

# Specific file
php artisan test tests/Feature/Games/GameTest.php
```

When adding a feature, tag at least one test with `->group('smoke')` to cover the critical path.

## Translations

We maintain English (`lang/en/`) and German (`lang/de/`) translations. See [lang/CONTRIBUTING_TRANSLATIONS.md](lang/CONTRIBUTING_TRANSLATIONS.md) for the full guide on adding and maintaining translations.

## Reporting Issues

- **Bugs:** Open an issue with steps to reproduce, expected behavior, and actual behavior
- **Feature requests:** Open an issue describing the use case and proposed solution
- **Security vulnerabilities:** See [SECURITY.md](SECURITY.md) — do not file public issues for security bugs

## License

By contributing, you agree your work will be licensed under [AGPL-3.0-or-later](LICENSE).
