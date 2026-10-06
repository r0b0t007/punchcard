<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * Makes a user a platform admin, or no longer one (CHW-22): the only way to
 * grant the role, run on the server by someone with shell access. An
 * anonymised account never becomes one. Prints who holds the role now.
 */
#[Signature('punchcard:admin {email : The user\'s email} {--revoke : Take the role away instead}')]
#[Description('Grant or revoke the platform admin role (Filament at /admin)')]
class AdminCommand extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->whereNull('anonymised_at')->first();

        if (! $user instanceof User) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $role = Role::findOrCreate(PlatformRole::Admin->value, 'web');

        if ($this->option('revoke')) {
            $user->removeRole($role);
            $this->info("{$user->email} is no longer an admin.");
        } else {
            $user->assignRole($role);
            $this->info("{$user->email} is an admin.");
        }

        $admins = User::role($role)->orderBy('email')->pluck('email')->all();
        $this->line('Admins: '.($admins === [] ? 'none' : implode(', ', $admins)));

        return self::SUCCESS;
    }
}
