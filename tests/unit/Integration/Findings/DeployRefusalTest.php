<?php

namespace Tests\Integration\Findings;

use App\Lib\Deploy\Detect\DeployabilityCheck;
use App\Lib\Deploy\Detect\DeployRefusal;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Deploy\Platform\UnknownRecipeException;
use App\Lib\Deploy\Platform\UnresolvedRuntimeException;
use PHPUnit\Framework\TestCase;

/** A refused deploy names its code and the file it is about, the same in inspect and in a failed deploy. */
class DeployRefusalTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/refusal-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    private function refusal(array $decision): DeployRefusal
    {
        try {
            DeployabilityCheck::assert($decision, $this->dir);
        } catch (DeployRefusal $e) {
            return $e;
        }
        $this->fail('Expected a refusal');
    }

    public function test_an_empty_directory(): void
    {
        $this->assertSame(DeployabilityCheck::PROJECT_EMPTY, $this->refusal(['strategy' => Strategies::STATIC])->refusal);
    }

    public function test_a_missing_root_file_names_the_file_and_the_strategy(): void
    {
        file_put_contents($this->dir.'/index.php', '<?php');

        $refusal = $this->refusal(['strategy' => Strategies::PHP]);

        $this->assertSame(DeployabilityCheck::ROOT_FILE_MISSING, $refusal->refusal);
        $this->assertSame(['file' => 'composer.json', 'strategy' => Strategies::PHP], $refusal->facts);
        $this->assertEquals($refusal->finding(), DeployRefusal::findingOf($refusal, 'unused'));
    }

    public function test_a_missing_dockerfile_names_it(): void
    {
        file_put_contents($this->dir.'/README', 'x');

        $refusal = $this->refusal(['strategy' => Strategies::DOCKERFILE, 'dockerfile' => 'Dockerfile.prod']);

        $this->assertSame([DeployabilityCheck::DOCKERFILE_MISSING, ['file' => 'Dockerfile.prod']], [$refusal->refusal, $refusal->facts]);
    }

    public function test_a_failure_keeps_a_refusals_code_and_names_anything_else_by_its_rule(): void
    {
        $refusal = new DeployRefusal(DeployabilityCheck::STATIC_INDEX_MISSING, 'No HTML.');
        $this->assertSame(DeployabilityCheck::STATIC_INDEX_MISSING, DeployFailureExplainer::failure($refusal, null, DeployFailureExplainer::DEPLOY_FAILED, 'x')->code);

        $output = 'npm ERR! code ENOSPC: no space left on device';
        $match = DeployFailureExplainer::match($output);
        $this->assertSame($match['rule'] ?? DeployFailureExplainer::DEPLOY_FAILED, DeployFailureExplainer::failure($output, $match, DeployFailureExplainer::DEPLOY_FAILED, 'x')->code);
        $this->assertSame(DeployFailureExplainer::APP_DID_NOT_START, DeployFailureExplainer::failure('nothing known', null, DeployFailureExplainer::APP_DID_NOT_START, 'x')->code);
    }

    public function test_a_failed_deploy_names_a_recipe_or_runtime_problem_as_inspect_does(): void
    {
        $runtime = DeployFailureExplainer::failure(new UnresolvedRuntimeException('php'), null, DeployFailureExplainer::DEPLOY_FAILED, 'x');
        $this->assertSame([UnresolvedRuntimeException::CODE, ['runtime' => 'php']], [$runtime->code, $runtime->facts]);

        $recipe = DeployRefusal::findingOf(new UnknownRecipeException('lavarel'), 'unused');
        $this->assertSame([UnknownRecipeException::CODE, ['recipe' => 'lavarel']], [$recipe->code, $recipe->facts]);
    }
}
