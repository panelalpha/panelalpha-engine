<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Exceptions\ProblemException;
use App\Http\Requests\WordpressMigrationStoreRequest;
use App\Jobs\MigrateWordpress;
use App\Models\WordpressMigration;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Records a WordPress migration with its secret encrypted and queues the job
 * that runs it, for POST /wordpress/migrations and wordpress:migrate alike.
 */
final class MigrationStarter
{
    /**
     * @param  array<string, mixed>  $validated  the start body, validated
     *
     * @throws ProblemException when the source site could not send the transfer to this engine
     */
    public function start(array $validated, MigrationTarget $target): WordpressMigration
    {
        TransferAddress::assertReachable();
        $access = SourceAccess::fromValidated($validated);
        $migration = WordpressMigration::start(
            sourceUrl: $access->url,
            username: $access->username,
            password: $access->password,
            method: $access->method,
            loginUrl: $access->loginUrl,
            sourceIp: $access->sourceIp,
            sourceToken: $access->sourceToken,
            target: $target,
        );
        MigrateWordpress::dispatch($migration->id);

        return $migration;
    }

    /**
     * The wordpress:migrate options, validated as the API body is.
     *
     * @param  array<string, string>  $input
     *
     * @throws ProblemException when the target is refused
     * @throws ValidationException when the input is not valid
     */
    public function startFromInput(array $input): WordpressMigration
    {
        $validated = Validator::make($input, WordpressMigrationStoreRequest::rulesFor($input))->validate();
        $target = (new MigrationTargetResolver)->resolveChoice(
            (string) $validated['url'],
            TargetChoice::fromValidated($validated),
        );

        return $this->start($validated, $target);
    }
}
