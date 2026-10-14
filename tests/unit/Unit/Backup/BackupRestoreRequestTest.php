<?php

namespace Tests\Unit\Backup;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use App\Http\Requests\BackupRestoreRequest;
use Tests\TestCase;

class BackupRestoreRequestTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     */
    private function makeRequest(array $payload): BackupRestoreRequest
    {
        $base = Request::create('/projects/alice/backups/1/restore', 'POST', $payload);
        $request = BackupRestoreRequest::createFrom($base);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));

        return $request;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validatorFails(array $payload): bool
    {
        $request = new BackupRestoreRequest();
        $validator = Validator::make($payload, $request->rules());
        $request->withValidator($validator);

        return $validator->fails();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, list<string>>
     */
    private function validatorErrors(array $payload): array
    {
        $request = new BackupRestoreRequest();
        $validator = Validator::make($payload, $request->rules());
        $request->withValidator($validator);
        $validator->fails();

        return $validator->errors()->toArray();
    }

    public function test_missing_confirm_fails(): void
    {
        $this->assertTrue($this->validatorFails([]));
        $this->assertTrue($this->validatorFails(['confirm' => false]));
    }

    public function test_confirm_true_without_filters_passes_and_getters_return_empty(): void
    {
        $this->assertFalse($this->validatorFails(['confirm' => true]));

        $request = $this->makeRequest(['confirm' => true]);
        $request->validateResolved();

        $this->assertSame([
            'files' => false,
            'volumes' => [],
            'databases' => [],
        ], $request->onlyFilter());
        $this->assertSame([
            'files' => false,
            'volumes' => [],
            'databases' => [],
        ], $request->excludeFilter());
    }

    public function test_only_files_passes(): void
    {
        $this->assertFalse($this->validatorFails([
            'confirm' => true,
            'only' => ['files' => true],
        ]));
    }

    public function test_exclude_files_passes(): void
    {
        $this->assertFalse($this->validatorFails([
            'confirm' => true,
            'exclude' => ['files' => true],
        ]));
    }

    public function test_cannot_combine_only_and_exclude(): void
    {
        $errors = $this->validatorErrors([
            'confirm' => true,
            'only' => ['files' => true],
            'exclude' => ['files' => true],
        ]);

        $this->assertSame(
            ['only' => ['Cannot combine include and exclude']],
            $errors,
        );
    }

    public function test_normalize_drops_empty_volume_names(): void
    {
        $request = $this->makeRequest([
            'confirm' => true,
            'only' => [
                'files' => 1,
                'volumes' => ['', 'data'],
            ],
        ]);
        $request->validateResolved();

        $this->assertSame([
            'files' => true,
            'volumes' => ['data'],
            'databases' => [],
        ], $request->onlyFilter());
    }
}
