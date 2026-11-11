<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Platform\Strategies;
use App\System\Project\Dind\ContainerOperations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ticket 05 (ADR-0001 D7): `up` and `pull` regenerate the run file from the
 * client's compose file first, but only where the run file is derived from it.
 */
class ContainerOperationsRunFileTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, bool, bool, bool}>
     */
    public static function cases(): iterable
    {
        // action, strategy, has git repo, has client compose, expected
        yield 'compose up' => ['up', Strategies::COMPOSE, true, true, true];
        yield 'compose pull' => ['pull', Strategies::COMPOSE, true, true, true];
        yield 'paemd up' => ['up', Strategies::PAEMD, true, true, true];
        yield 'compose from an archive' => ['up', Strategies::COMPOSE, false, true, true];
        yield 'compose restart' => ['restart', Strategies::COMPOSE, true, true, false];
        yield 'compose stop' => ['stop', Strategies::COMPOSE, true, true, false];
        yield 'compose down' => ['down', Strategies::COMPOSE, true, true, false];
        yield 'compose start' => ['start', Strategies::COMPOSE, true, true, false];
        yield 'welcome account the client filled in' => ['up', null, false, true, true];
        yield 'welcome account pull' => ['pull', null, false, true, true];
        yield 'welcome account still empty' => ['up', null, false, false, false];
        yield 'git project with no strategy yet' => ['up', null, true, true, false];
        yield 'laravel recipe' => ['up', Strategies::LARAVEL, true, true, false];
        yield 'dockerfile recipe' => ['up', Strategies::DOCKERFILE, true, true, false];
        yield 'railpack recipe' => ['pull', Strategies::RAILPACK, true, false, false];
        yield 'static recipe from an archive' => ['up', Strategies::STATIC, false, true, false];
    }

    #[DataProvider('cases')]
    public function test_regenerates_run_file_only_where_it_derives_from_the_client_compose(
        string $action,
        ?string $strategy,
        bool $hasGitRepo,
        bool $hasClientCompose,
        bool $expected,
    ): void {
        $this->assertSame(
            $expected,
            ContainerOperations::regeneratesRunFile($action, $strategy, $hasGitRepo, $hasClientCompose)
        );
    }
}
