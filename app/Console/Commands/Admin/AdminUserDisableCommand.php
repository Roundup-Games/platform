<?php

namespace App\Console\Commands\Admin;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class AdminUserDisableCommand extends Command
{
    protected $signature = 'admin:user:disable
        {--email= : Email of the user to disable}
        {--revoke-roles : Also remove admin role(s)}';

    protected $description = 'Disable a user account (blocks login, invalidates sessions)';

    public function handle(): int
    {
        $email = $this->option('email') ?? $this->askForUserEmail();
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email '{$email}'.");

            return self::FAILURE;
        }

        if ($user->isDisabled()) {
            $this->warn("User '{$email}' is already disabled.");

            return self::SUCCESS;
        }

        if ($user->id === auth()->id()) {
            $this->error('You cannot disable your own account.');

            return self::FAILURE;
        }

        $platformAdminCount = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', User::class)
            ->where('roles.name', 'Platform Admin')
            ->whereNull('model_has_roles.team_id')
            ->pluck('model_has_roles.model_id')
            ->unique()
            ->filter(fn (mixed $id) => is_string($id) && ! User::find($id)?->isDisabled())
            ->count();

        $hasPlatformAdmin = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.model_id', $user->id)
            ->where('roles.name', 'Platform Admin')
            ->whereNull('model_has_roles.team_id')
            ->exists();

        if ($hasPlatformAdmin && $platformAdminCount <= 1) {
            $this->error('Cannot disable the last Platform Admin. Promote another user first.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('User', $user->email);
        $this->components->twoColumnDetail('Name', $user->name);

        $globalRoles = $this->getGlobalRoles($user);
        if ($globalRoles->isNotEmpty()) {
            $this->components->twoColumnDetail('Current role(s)', $globalRoles->join(', '));
        }

        if (! $this->confirm('Disable this user?', true)) {
            $this->info('No changes made.');

            return self::SUCCESS;
        }

        $user->update([
            'is_disabled' => true,
            'disabled_at' => now(),
        ]);

        $deleted = DB::table('sessions')->where('user_id', $user->id)->delete();
        $this->components->twoColumnDetail('Sessions killed', (string) $deleted);

        if ($this->option('revoke-roles')) {
            foreach ($globalRoles as $role) {
                $user->removeRole($role);
            }
            if ($globalRoles->isNotEmpty()) {
                $this->components->twoColumnDetail('Roles revoked', $globalRoles->join(', '));
            }
        }

        $this->info("User '{$email}' has been disabled.");

        return self::SUCCESS;
    }

    private function askForUserEmail(): string
    {
        $users = User::where('is_disabled', false)
            ->orderBy('name')
            ->limit(20)
            ->get(['email', 'name']);

        if ($users->isNotEmpty()) {
            return (string) select(
                label: 'Select user to disable',
                options: $users->mapWithKeys(fn (User $u) => [$u->email => "{$u->name} ({$u->email})"])->all(),
            );
        }

        return text(label: 'Email address', required: true);
    }

    /**
    /**
     * @return Collection<int, string>
     */
    private function getGlobalRoles(User $user): Collection
    {
        return DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.model_id', $user->id)
            ->whereNull('model_has_roles.team_id')
            ->pluck('roles.name')
            ->filter(fn (mixed $name) => is_string($name))
            ->map(fn (mixed $name): string => (string) $name);
    }
}
