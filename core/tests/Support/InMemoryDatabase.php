<?php

namespace Tests\Support;

use App\Models\Domain as DomainModel;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * A throwaway sqlite database with the real schema.
 *
 * The migrations run on sqlite in about a tenth of a second, so there is no
 * reason for a test to hand-roll the two or three tables it thinks it needs and
 * then fail on the fourth one a resource happens to touch.
 */
trait InMemoryDatabase
{
    protected function bootInMemoryDatabase(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * `users.domain` is NOT NULL in the real schema, so it is defaulted here
     * rather than left to every caller.
     *
     * @param array<string, mixed> $details
     */
    protected function makeUser(string $username, array $details = [], ?string $domain = null): User
    {
        /** @var User $user */
        $user = User::make([
            'username' => $username,
            'domain' => $domain ?? $username . '.example.test',
            'details' => $details,
        ]);
        $user->save();

        return $user;
    }

    protected function makeMainDomain(User $user, string $domain): DomainModel
    {
        /** @var DomainModel $model */
        $model = DomainModel::make([
            'user_id' => $user->id,
            'domain' => $domain,
            'type' => 'main',
            'details' => ['document_root' => "/{$domain}/public_html"],
        ]);
        $model->save();

        return $model;
    }
}
