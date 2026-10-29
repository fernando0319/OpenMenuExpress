<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Grants (or removes) access to the private admin panel. There is no screen for this on purpose:
 * only someone with access to the server can create a platform admin.
 */
#[Signature('app:super-admin {login : E-mail or username} {--revoke : Remove the access instead}')]
#[Description('Grant or revoke access to the platform admin panel')]
class MakeSuperAdmin extends Command
{
    public function handle(): int
    {
        $argument = $this->argument('login');
        $login = mb_strtolower(trim(is_string($argument) ? $argument : ''));

        $user = str_contains($login, '@')
            ? User::query()->whereRaw('LOWER(email) = ?', [$login])->first()
            : User::query()->where('username', $login)->first();

        if ($user === null) {
            $this->error("Nenhum usuário encontrado para \"{$login}\".");

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        $user->forceFill(['is_super_admin' => $grant])->save();

        $this->info($grant
            ? "{$user->name} agora acessa o painel administrativo em /admin."
            : "{$user->name} não acessa mais o painel administrativo.");

        return self::SUCCESS;
    }
}
