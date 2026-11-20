<?php

namespace Tests\Integration\System\Privileged;

use App\Lib\Deploy\Dind\AccountImage;
use App\System\Project\Dind\AccountRuntime;
use App\System\Project\Dind\LxcfsProc;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The account compose files in compose-cases.json, whose shape the broker accepts, are what the templates render. */
final class AccountComposeCasesTest extends TestCase
{
    private const CASES = '/dockerfiles/root-broker/tests/compose-cases.json';

    private const TEMPLATES = ['templates/user/dind/project', 'templates/user/default/project'];

    /** @return list<array<string, mixed>> */
    private static function cases(): array
    {
        return json_decode((string) file_get_contents(self::root().self::CASES), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 5);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function variants(): iterable
    {
        foreach (self::cases() as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('variants')]
    public function test_the_template_renders_the_case_word_for_word(array $case): void
    {
        $template = (string) file_get_contents(self::root().'/'.$case['template']);

        $this->assertSame($case['compose'], Blade::render($template, $case['vars']));
    }

    public function test_every_account_compose_template_has_a_case(): void
    {
        $templates = [];
        foreach (self::TEMPLATES as $directory) {
            foreach (glob(self::root().'/'.$directory.'/docker-compose.yml*.blade.php') ?: [] as $file) {
                $templates[] = $directory.'/'.basename($file);
            }
        }
        $cased = array_values(array_unique(array_column(self::cases(), 'template')));
        sort($templates);
        sort($cased);

        $this->assertSame($templates, $cased);
    }

    public function test_the_dind_cases_use_the_values_the_engine_passes(): void
    {
        $isolations = [];
        foreach ([AccountRuntime::SYSBOX, AccountRuntime::PRIVILEGED] as $runtime) {
            config(['env.DIND_RUNTIME' => $runtime]);
            $isolations[AccountRuntime::composeIsolation()] = LxcfsProc::mounts(LxcfsProc::FILES, AccountRuntime::isSysbox());
        }
        foreach (self::cases() as $case) {
            if (! isset($case['vars']['isolation'])) {
                continue;
            }
            $this->assertSame(AccountImage::REF, $case['vars']['image'], $case['name']);
            $this->assertArrayHasKey($case['vars']['isolation'], $isolations, $case['name']);
            $this->assertContains($case['vars']['proc_mounts'], [[], $isolations[$case['vars']['isolation']]], $case['name']);
        }
    }
}
