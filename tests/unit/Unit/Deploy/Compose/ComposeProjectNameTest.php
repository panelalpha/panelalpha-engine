<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeProjectName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComposeProjectNameTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, list<string>, string}> */
    public static function names(): iterable
    {
        yield 'the project directory' => ['/home/alice/project', ["services: {}\n"], [], 'project'];
        yield 'the directory made valid' => ['/srv/_My App.v2', [], [], 'myappv2'];
        yield 'the file names it' => ['/home/alice/project', ["name: wordpress\nservices: {}\n"], [], 'wordpress'];
        yield 'the last file that names it' => ['/home/alice/project', ["name: a\n", "services: {}\n", "name: b\n"], [], 'b'];
        yield 'the env files win over the file' => ['/home/alice/project', ["name: wordpress\n"], ["COMPOSE_PROJECT_NAME=shop\n"], 'shop'];
        yield 'the later env file wins' => ['/home/alice/project', [], ["COMPOSE_PROJECT_NAME=a\n", "COMPOSE_PROJECT_NAME=b\n"], 'b'];
        yield 'an empty variable names nothing' => ['/home/alice/project', ["name: wordpress\n"], ["COMPOSE_PROJECT_NAME=\n"], 'wordpress'];
        yield 'a name from a variable' => ['/home/alice/project', ['name: ${APP}'], ["APP=shop\n"], 'shop'];
        yield 'a default for an unset variable' => ['/home/alice/project', ['name: ${APP:-shop}'], [], 'shop'];
        yield 'a default for an empty variable' => ['/home/alice/project', ['name: ${APP:-shop}'], ["APP=\n"], 'shop'];
        yield 'no default for a set empty variable' => ['/home/alice/project', ['name: x${APP-shop}'], ["APP=\n"], 'x'];
        yield 'an alternative for a set variable' => ['/home/alice/project', ['name: ${APP:+shop}'], ["APP=1\n"], 'shop'];
        yield 'an unset variable alone is no name' => ['/home/alice/project', ['name: ${APP}'], [], 'project'];
        yield 'a doubled dollar' => ['/home/alice/project', ['name: a$$b'], [], 'ab'];
        yield 'the file\'s name made valid' => ['/home/alice/project', ["name: My_Project.v2\n"], [], 'my_projectv2'];
        yield 'a variable\'s value made valid' => ['/home/alice/project', ['name: ${APP}'], ["APP=My.App\n"], 'myapp'];
        yield 'a default from another variable' => ['/home/alice/project', ['name: ${APP:-${SHOP}}'], ["SHOP=inner\n"], 'inner'];
        yield 'a default from an unset variable is no name' => ['/home/alice/project', ['name: ${APP:-${SHOP}}'], [], 'project'];
        yield 'an alternative from another variable' => ['/home/alice/project', ['name: ${APP:+${SHOP}-x}'], ["APP=1\nSHOP=shop\n"], 'shop-x'];
        yield 'a file it cannot read names nothing' => ['/home/alice/project', ['', "name: [\n"], [], 'project'];
        yield 'a later file\'s name that is unset falls back to the directory' => ['/home/alice/project', ["name: first\n", 'name: ${NOTSET}'], [], 'project'];
        yield 'a later file\'s empty name names nothing' => ['/home/alice/project', ["name: first\n", "name: ''\n"], [], 'first'];
        yield 'a required variable that is set' => ['/home/alice/project', ['name: ${APP:?set it}'], ["APP=shop\n"], 'shop'];
        yield 'a required-if-unset variable that is empty' => ['/home/alice/project', ['name: x${APP?set it}'], ["APP=\n"], 'x'];
    }

    /**
     * @param  list<string>  $composeFiles
     * @param  list<string>  $envFiles
     */
    #[DataProvider('names')]
    public function test_the_name_is_the_one_compose_gives(string $directory, array $composeFiles, array $envFiles, string $expected): void
    {
        $this->assertSame($expected, ComposeProjectName::of($directory, $composeFiles, $envFiles));
    }

    /** @return iterable<string, array{string, list<string>, list<string>, string}> */
    public static function refused(): iterable
    {
        $invalid = ': must consist only of lowercase alphanumeric characters, hyphens, and underscores as well as start with a letter or number';

        yield 'a required variable that is unset' => ['/home/alice/project', ['name: ${V:?message}'], [], 'required variable V is missing a value: message'];
        yield 'a required variable that is empty' => ['/home/alice/project', ['name: ${V:?}'], ["V=\n"], 'required variable V is missing a value'];
        yield 'a required-if-unset variable that is unset' => ['/home/alice/project', ['name: ${V?no ${W:-w}}'], [], 'required variable V is missing a value: no w'];
        yield 'a required variable in an earlier file' => ['/home/alice/project', ['name: ${V:?message}', "name: shop\n"], [], 'required variable V is missing a value: message'];
        yield 'a number as the name' => ['/home/alice/project', ["name: 123\n"], [], 'name must be a string'];
        yield 'a list as the name' => ['/home/alice/project', ["name: [a, b]\n"], [], 'name must be a string'];
        yield 'a boolean as the name' => ['/home/alice/project', ["name: true\n"], [], 'name must be a string'];
        yield 'a number as an earlier file\'s name' => ['/home/alice/project', ["name: 123\n", "name: shop\n"], [], 'name must be a string'];
        yield 'an invalid variable' => ['/home/alice/project', ["name: shop\n"], ["COMPOSE_PROJECT_NAME=My_App\n"], 'invalid project name "My_App"'.$invalid];
        yield 'a variable starting with a hyphen' => ['/home/alice/project', [], ["COMPOSE_PROJECT_NAME=-shop\n"], 'invalid project name "-shop"'.$invalid];
        yield 'a directory that makes no name' => ['/srv/___', ["services: {}\n"], [], 'project name must not be empty'];
    }

    /**
     * @param  list<string>  $composeFiles
     * @param  list<string>  $envFiles
     */
    #[DataProvider('refused')]
    public function test_a_name_compose_refuses_is_refused_with_its_reason(string $directory, array $composeFiles, array $envFiles, string $reason): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException($reason));

        ComposeProjectName::of($directory, $composeFiles, $envFiles);
    }

    /** @return iterable<string, array{list<string>, list<string>, array<string, string>, string}> */
    public static function processEnvironments(): iterable
    {
        $client = ['PATH' => '/usr/bin', 'HOME' => '/home/alice', 'USER' => 'alice', 'PWD' => '/home/alice/project'];

        yield 'the project directory from PWD, made valid' => [['name: ${PWD}'], [], $client, 'homealiceproject'];
        yield 'the client\'s own variable over the env file' => [['name: ${USER}-shop'], ["USER=bob\n"], $client, 'alice-shop'];
        yield 'an env file\'s variable the client does not set' => [['name: ${USER}-${APP}'], ["APP=shop\n"], $client, 'alice-shop'];
        yield 'the client\'s COMPOSE_PROJECT_NAME over the env file' => [[], ["COMPOSE_PROJECT_NAME=bob\n"], ['COMPOSE_PROJECT_NAME' => 'alice'], 'alice'];
    }

    /**
     * @param  list<string>  $composeFiles
     * @param  list<string>  $envFiles
     * @param  array<string, string>  $processEnvironment
     */
    #[DataProvider('processEnvironments')]
    public function test_the_clients_own_environment_wins_over_the_env_files(array $composeFiles, array $envFiles, array $processEnvironment, string $expected): void
    {
        $this->assertSame($expected, ComposeProjectName::of('/home/alice/project', $composeFiles, $envFiles, $processEnvironment));
    }
}
