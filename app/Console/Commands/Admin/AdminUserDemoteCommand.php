<?php

namespace App\Console\Commands\Admin;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class AdminUserDemoteCommand extends Command
{
    protected $signature = 'admin:user:demote
        {--email= : Email of the user to demote}';

    protected $description = 'Remove admin role(s) from a user';

    public function handle(): int
    {
        $email = $this->option('email') ?? $this->askForAdminEmail();
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email '{$email}'.");

            return self::FAILURE;
        }

        $globalRoles = $this->getGlobalRoles($user);

        if ($globalRoles->isEmpty()) {
            $this->warn("User '{$email}' has no global admin roles.");

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('User', $user->email);
        $this->components->twoColumnDetail('Current role(s)', $globalRoles->join(', '));

        if (! $this->confirm('Remove all admin roles from this user?', true)) {
            $this->info('No changes made.');

            return self::SUCCESS;
        }

        foreach ($globalRoles as $roleName) {
            $user->removeRole($roleName);
        }

        $this->info("All admin roles removed from '{$email}'.");

        return self::SUCCESS;
    }

    private function askForAdminEmail(): string
    {
        $adminUserIds = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', User::class)
            ->whereNull('model_has_roles.team_id')
            ->whereIn('roles.name', ['Platform Admin', 'Games Admin'])
            ->pluck('model_has_roles.model_id')
            ->unique();

        $admins = User::whereIn('id', $adminUserIds)->orderBy('name')->get(['email', 'name']);

        if ($admins->isNotEmpty()) {
            return select(
                label: 'Select admin user to demote',
                options: $admins->mapWithKeys(fn ($u) => [$u->email => "{$u->name} ({$u->email})"]),
            );
        }

        return text(label: 'Email address', required: true);
    }

    /**
     * Get global role names for a user (team_id=null on model_has_roles).
     * Avoids the ambiguous team_id column that appears in Spatie's roles() join.
     */
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
