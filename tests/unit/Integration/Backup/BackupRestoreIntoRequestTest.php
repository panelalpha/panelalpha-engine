<?php

namespace Tests\Integration\Backup;

use App\Http\Requests\BackupRestoreIntoRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class BackupRestoreIntoRequestTest extends TestCase
{
    public function test_the_new_project_needs_a_name(): void
    {
        $this->assertTrue($this->validatorFails([]));
        $this->assertTrue($this->validatorFails(['new_username' => '']));
    }

    public function test_a_name_outside_the_project_name_grammar_fails(): void
    {
        $this->assertTrue($this->validatorFails(['new_username' => 'ab']));
        $this->assertTrue($this->validatorFails(['new_username' => 'Upper']));
        $this->assertTrue($this->validatorFails(['new_username' => 'with-dash']));
        $this->assertFalse($this->validatorFails(['new_username' => 'shop2']));
    }

    public function test_a_domain_must_be_a_hostname(): void
    {
        $this->assertTrue($this->validatorFails(['new_username' => 'shop2', 'domain' => 'https://shop.example.com']));
        $this->assertFalse($this->validatorFails(['new_username' => 'shop2', 'domain' => 'shop.example.com']));
    }

    public function test_the_create_it_builds_carries_the_new_name_and_the_dind_template(): void
    {
        $request = $this->request([
            'new_username' => 'shop2',
            'domain' => 'shop2.example.com',
            'email' => 'Ops@Example.com',
        ]);

        $input = $request->newProjectInput();

        $this->assertSame('new_username', $input->nameField);
        $this->assertSame('shop2', $input->params['username']);
        $this->assertSame('shop2.example.com', $input->params['domain']);
        $this->assertSame('dind', $input->params['template']);
        $this->assertArrayNotHasKey('new_username', $input->params);
    }

    public function test_a_name_sent_capitalised_is_lowered(): void
    {
        $request = $this->request(['new_username' => 'Shop2', 'domain' => 'Shop2.Example.com']);

        $this->assertSame('shop2', $request->newProjectInput()->params['username']);
        $this->assertSame('shop2.example.com', $request->newProjectInput()->params['domain']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatorFails(array $payload): bool
    {
        return Validator::make($payload, (new BackupRestoreIntoRequest())->rules())->fails();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function request(array $payload): BackupRestoreIntoRequest
    {
        $request = BackupRestoreIntoRequest::createFrom(
            Request::create('/api/projects/alice/backups/1/restore-into', 'POST', $payload)
        );
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        $request->validateResolved();

        return $request;
    }
}
