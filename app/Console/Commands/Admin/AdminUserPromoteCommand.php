<?php

namespace App\Console\Commands\Admin;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class AdminUserPromoteCommand extends Command
{
    protected $signature = 'admin:user:promote
        {--email= : Email of the user to promote}
        {--role= : Role to assign (Platform Admin or Games Admin)}';

    protected $description = 'Assign an admin role to an existing user';

    public function handle(): int
    {
        $this->ensureRolesExist();

        $email = $this->option('email') ?? $this->askForUserEmail();
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email '{$email}'.");

            return self::FAILURE;
        }

        if ($user->isDisabled()) {
            $this->error("User '{$email}' is currently disabled. Enable them first with admin:user:enable.");

            return self::FAILURE;
        }

        $roleName = $this->option('role') ?? (string) select(
            label: 'Role to assign',
            options: ['Platform Admin', 'Games Admin'],
            default: 'Platform Admin',
        );

        if ($user->hasRole($roleName)) {
            $this->warn("User '{$email}' already has the '{$roleName}' role.");

            return self::SUCCESS;
        }

        $existingRoles = $this->getGlobalRoles($user);
        if ($existingRoles->isNotEmpty()) {
            $this->warn("User currently has role(s): {$existingRoles->join(', ')}");
            if (! $this->confirm("Replace with '{$roleName}'?", true)) {
                $this->info('No changes made.');

                return self::SUCCESS;
            }
            foreach ($existingRoles as $existing) {
                $user->removeRole($existing);
            }
        }

        $user->assignRole($roleName);

        $this->components->twoColumnDetail('User', $user->email);
        $this->components->twoColumnDetail('Role assigned', $roleName);

        return self::SUCCESS;
    }

    private function askForUserEmail(): string
    {
        $users = User::orderBy('name')->limit(20)->get(['email', 'name']);
        if ($users->isNotEmpty()) {
            return (string) select(
                label: 'Select user to promote',
                options: $users->mapWithKeys(fn (User $u) => [$u->email => "{$u->name} ({$u->email})"])->all(),
            );
        }

        return text(label: 'Email address', required: true);
    }

    private function ensureRolesExist(): void
    {
        if (! Role::where('name', 'Platform Admin')->whereNull('team_id')->exists()) {
            $this->warn('Admin roles not found. Seeding roles...');
            $this->call('db:seed', ['--class' => 'RoleSeeder', '--force' => true]);
        }
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
            ->pluck('roles.name')->filter(fn (mixed $name) => is_string($name))->map(fn (mixed $name): string => (string) $name);
    }
}
