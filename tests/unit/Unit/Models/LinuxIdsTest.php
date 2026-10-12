<?php

namespace Tests\Unit\Models;

use App\Exceptions\MissingLinuxUserException;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Tests\TestCase;

/** An account without its own UID/GID is refused, never handled as www-data (33). */
class LinuxIdsTest extends TestCase
{
    public function test_the_ids_are_the_accounts_own(): void
    {
        $user = new User();
        $user->username = 'acct';
        $user->setDetails(['UID' => 1012, 'GID' => 1013]);

        $this->assertSame([1012, 1013], $user->linuxIds());
    }

    public function test_an_account_without_them_is_refused(): void
    {
        foreach ([[], ['UID' => 1012], ['GID' => 1013]] as $details) {
            $user = new User();
            $user->username = 'acct';
            $user->setDetails($details);

            try {
                $user->linuxIds();
                $this->fail('ids were returned for ' . json_encode($details));
            } catch (MissingLinuxUserException $e) {
                $this->assertSame("Project 'acct' has no Linux user recorded; rebuild it to create one.", $e->getMessage());
            }
        }
    }

    public function test_the_api_answers_409_with_the_reason(): void
    {
        $response = $this->app->make(ExceptionHandler::class)
            ->render(Request::create('/api/projects/acct/files'), MissingLinuxUserException::for('acct'));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(
            ['message' => "Project 'acct' has no Linux user recorded; rebuild it to create one."],
            json_decode((string) $response->getContent(), true)
        );
    }
}
