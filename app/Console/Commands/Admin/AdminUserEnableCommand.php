<?php

namespace App\Console\Commands\Admin;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\select;

class AdminUserEnableCommand extends Command
{
    protected $signature = 'admin:user:enable
        {--email= : Email of the user to enable}';

    protected $description = 'Re-enable a previously disabled user account';

    public function handle(): int
    {
        $email = $this->option('email') ?? $this->askForDisabledUserEmail();
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email '{$email}'.");

            return self::FAILURE;
        }

        if (! $user->isDisabled()) {
            $this->warn("User '{$email}' is not disabled.");

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('User', $user->email);
        $this->components->twoColumnDetail('Name', $user->name);
        $this->components->twoColumnDetail('Disabled at', $user->disabled_at?->format('Y-m-d H:i') ?? 'unknown');

        $globalRoles = $this->getGlobalRoles($user);
        if ($globalRoles->isNotEmpty()) {
            $this->components->twoColumnDetail('Retained role(s)', $globalRoles->join(', '));
        } else {
            $this->components->twoColumnDetail('Roles', 'none (re-assign with admin:user:promote)');
        }

        if (! $this->confirm('Re-enable this user?', true)) {
            $this->info('No changes made.');

            return self::SUCCESS;
        }

        $user->update([
            'is_disabled' => false,
            'disabled_at' => null,
        ]);

        $this->info("User '{$email}' has been re-enabled.");

        return self::SUCCESS;
    }

    private function askForDisabledUserEmail(): string
    {
        $disabled = User::where('is_disabled', true)
            ->orderByDesc('disabled_at')
            ->get(['email', 'name', 'disabled_at']);

        if ($disabled->isNotEmpty()) {
            return select(
                label: 'Select user to enable',
                options: $disabled->mapWithKeys(function ($u) {
                    $when = $u->disabled_at?->diffForHumans() ?? 'unknown';
                    return [$u->email => "{$u->name} ({$u->email}) — disabled {$when}"];
                }),
            );
        }

        return text(label: 'Email address', required: true);
    }

    private function getGlobalRoles(User $user): \Illuminate\Support\Collection
    {
        return DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.model_id', $user->id)
            ->whereNull('model_has_roles.team_id')
            ->pluck('roles.name');
    }
}
