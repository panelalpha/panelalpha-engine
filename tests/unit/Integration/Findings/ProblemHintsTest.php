<?php

namespace Tests\Integration\Findings;

use App\Exceptions\DeployBusyException;
use App\Http\Middleware\EnsureProjectNotProtected;
use App\Http\Middleware\EnsureTokenMayUseApi;
use App\Http\Responses\Refusal;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Findings\Finding;
use App\Lib\Findings\Hints\FindingPresenter;
use App\Lib\Upload\Uploads;
use App\System\Project\Dind\HostDiskLowException;
use Tests\TestCase;

/** A refusal's `problems`: each keeps what it said and gains the hints its code has. */
class ProblemHintsTest extends TestCase
{
    public function test_a_problem_keeps_its_fields_and_gains_the_hints_of_its_code(): void
    {
        [$problem] = FindingPresenter::problems([
            ['field' => 'project', 'code' => DeployBusyException::TASK, 'message' => 'Busy.', 'task_id' => 7],
        ], 'shop');

        $this->assertSame(['field', 'code', 'message', 'task_id', 'hints'], array_keys($problem));
        $this->assertSame([
            ['type' => 'rest', 'action' => 'follow_task', 'method' => 'GET', 'path' => '/tasks/{id}', 'path_params' => ['id' => 7]],
            ['type' => 'mcp', 'action' => 'follow_task', 'tool' => 'task_get', 'arguments' => ['id' => 7]],
            ['type' => 'rest', 'action' => 'cancel_task', 'method' => 'POST', 'path' => '/tasks/{id}/cancel', 'path_params' => ['id' => 7]],
            ['type' => 'mcp', 'action' => 'cancel_task', 'tool' => 'task_cancel', 'arguments' => ['id' => 7]],
        ], $problem['hints']);
    }

    public function test_a_problem_nothing_acts_on_has_an_empty_list(): void
    {
        [$problem] = FindingPresenter::problems([['field' => 'name', 'code' => 'name_invalid', 'message' => 'Bad name.']], null);

        $this->assertSame([], $problem['hints']);
    }

    public function test_an_operator_only_fix_is_a_command(): void
    {
        $this->assertSame(
            [['type' => 'cli', 'action' => 'mint_api_token', 'command' => 'pae api:token:create <name>']],
            FindingPresenter::problems([['field' => 'token', 'code' => EnsureTokenMayUseApi::NOT_FOR_API, 'message' => 'x']], null)[0]['hints'],
        );
        $this->assertSame(
            [['type' => 'cli', 'action' => 'unprotect_project', 'command' => 'pae project:settings:unset protected --project=shop']],
            FindingPresenter::problems([['field' => 'project', 'code' => EnsureProjectNotProtected::CODE, 'message' => 'x']], 'shop')[0]['hints'],
        );
    }

    public function test_an_unpinned_host_is_pinned_on_the_deploy_key(): void
    {
        $hints = FindingPresenter::problems([[
            'field' => 'repo_url', 'code' => 'repo_url_ssh_host_not_pinned', 'message' => 'x', 'host' => 'git.example.com',
        ]], 'shop')[0]['hints'];

        $this->assertSame(['username' => 'shop'], $hints[0]['path_params']);
        $this->assertSame(['host' => 'git.example.com'], $hints[0]['body']);
        $this->assertSame('pae git:deploy-key shop --host=git.example.com', $hints[2]['command']);
    }

    public function test_a_missing_upload_is_uploaded(): void
    {
        $hints = FindingPresenter::problems([['field' => 'upload_id', 'code' => Uploads::NOT_FOUND, 'message' => 'x']], 'shop')[0]['hints'];

        $this->assertSame([['rest', 'POST', '/uploads'], ['mcp', null, null]], array_map(
            static fn (array $hint): array => [$hint['type'], $hint['method'] ?? null, $hint['path'] ?? null],
            $hints,
        ));
        $this->assertSame('upload_create', $hints[1]['tool']);
    }

    public function test_a_refusal_answered_by_hand_has_the_shape_of_a_422(): void
    {
        $response = Refusal::json(409, 'project', EnsureProjectNotProtected::CODE, 'Protected.', 'shop');
        $body = $response->getData(true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(['message', 'code', 'problems'], array_keys($body));
        $this->assertSame(['field' => 'project', 'code' => EnsureProjectNotProtected::CODE, 'message' => 'Protected.'], array_diff_key($body['problems'][0], ['hints' => true]));
    }

    public function test_a_host_short_of_disk_fails_the_deploy_under_its_own_code(): void
    {
        $failure = DeployFailureExplainer::failure(new HostDiskLowException('Low.', '/var/lib/docker'), null, DeployFailureExplainer::DEPLOY_FAILED, 'Low.');

        $this->assertSame([HostDiskLowException::CODE, ['path' => '/var/lib/docker']], [$failure->code, $failure->facts]);
        $this->assertContains('prune_host_images', array_column(FindingPresenter::presentOne($failure, 'shop')['hints'], 'action'));
    }

    /** Only the host can give a project a new container once sysbox-fs stopped serving its old one. */
    public function test_a_lost_sysbox_fs_is_answered_with_the_command_that_recreates_the_container(): void
    {
        $failure = new Finding('sysbox-fs-connection-lost', 'Lost.');

        $this->assertSame(
            ['type' => 'cli', 'action' => 'recreate_project_container', 'command' => 'pae project:dind:recover --project=shop --recreate'],
            FindingPresenter::presentOne($failure, 'shop')['hints'][0],
        );
    }
}
