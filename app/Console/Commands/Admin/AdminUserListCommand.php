<?php

namespace App\Console\Commands\Admin;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AdminUserListCommand extends Command
{
    protected $signature = 'admin:user:list
        {--with-disabled : Include disabled users in the listing}';

    protected $description = 'List admin users and their role assignments';

    public function handle(): int
    {
        $adminUserIds = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', User::class)
            ->whereNull('model_has_roles.team_id')
            ->whereIn('roles.name', ['Platform Admin', 'Games Admin'])
            ->pluck('model_has_roles.model_id')
            ->unique();

        $query = User::whereIn('id', $adminUserIds);

        if (! $this->option('with-disabled')) {
            $query->where('is_disabled', false);
        }

        $users = $query->orderBy('name')->get();

        if ($users->isEmpty()) {
            $this->warn('No admin users found.');

            return self::SUCCESS;
        }

        $rolesByUser = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', User::class)
            ->whereNull('model_has_roles.team_id')
            ->whereIn('model_has_roles.model_id', $users->pluck('id'))
            ->groupBy('model_has_roles.model_id')
            ->select('model_has_roles.model_id', DB::raw("string_agg(roles.name, ', ') as roles"))
            ->get()
            ->keyBy('model_id');

        $this->table(
            ['Name', 'Email', 'Role(s)', 'Status', 'Created'],
            $users->map(fn (User $user) => [
                $user->name,
                $user->email,
                $rolesByUser->get($user->id)?->roles ?? '—',
                $user->isDisabled()
                    ? "<fg=red;options=bold>disabled</> ({$user->disabled_at?->format('Y-m-d')})"
                    : '<fg=green;options=bold>active</>',
                $user->created_at->format('Y-m-d'),
            ]),
        );

        return self::SUCCESS;
    }
}
