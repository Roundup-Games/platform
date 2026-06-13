<?php

namespace App\Console\Commands\Admin;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class AdminUserCreateCommand extends Command
{
    protected $signature = 'admin:user:create
        {--name= : The user\'s display name}
        {--email= : A valid and unique email address}
        {--password= : Password (min 8 characters)}
        {--role= : Role to assign (Platform Admin or Games Admin)}
        {--skip-profile : Skip profile completion requirements}';

    protected $description = 'Create a new admin user and assign a role';

    public function handle(): int
    {
        $this->ensureRolesExist();

        $name = $this->option('name') ?? text(label: 'Name', required: true);
        $email = $this->option('email') ?? text(
            label: 'Email address',
            required: true,
            validate: fn (string $value): ?string => match (true) {
                ! filter_var($value, FILTER_VALIDATE_EMAIL) => 'Please enter a valid email address.',
                User::where('email', $value)->exists() => 'A user with this email already exists.',
                default => null,
            },
        );
        $password = $this->option('password') ?? password(
            label: 'Password',
            required: true,
            validate: fn (string $value): ?string => strlen($value) < 8
                ? 'Password must be at least 8 characters.'
                : null,
        );
        $roleName = $this->option('role') ?? (string) select(
            label: 'Role',
            options: ['Platform Admin', 'Games Admin'],
            default: 'Platform Admin',
        );

        if (! Role::where('name', $roleName)->whereNull('team_id')->exists()) {
            $this->error("Role '{$roleName}' does not exist. Run db:seed --class=RoleSeeder first.");

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'profile_complete' => $this->option('skip-profile'),
            ...($this->option('skip-profile') ? [
                'gender' => 'prefer_not_to_say',
                'pronouns' => 'prefer_not_to_say',
            ] : []),
        ]);

        $user->assignRole($roleName);

        app()->setLocale(is_string($l = config('app.locale', 'en')) ? $l : 'en');
        URL::defaults(['locale' => app()->getLocale()]);

        $loginUrl = route('login', [], false);

        $this->components->twoColumnDetail('User created', $user->email);
        $this->components->twoColumnDetail('Role assigned', $roleName);
        $this->components->twoColumnDetail('Login at', $loginUrl);

        if (! $this->option('skip-profile')) {
            warning('This user will be redirected to complete their profile on first login.');
        }

        return self::SUCCESS;
    }

    private function ensureRolesExist(): void
    {
        if (! Role::where('name', 'Platform Admin')->whereNull('team_id')->exists()) {
            $this->warn('Admin roles not found. Seeding roles...');
            $this->call('db:seed', ['--class' => 'RoleSeeder', '--force' => true]);
        }
    }
}
