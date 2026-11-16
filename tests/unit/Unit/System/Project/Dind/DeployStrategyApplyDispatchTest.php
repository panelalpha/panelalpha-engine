<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\Strategies;
use App\System\Project\Dind;
use App\System\Project\Dind\ComposeWriter;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\Strategy\DockerfileStrategy;
use App\System\Project\Dind\Strategy\FrameworkStrategy;
use App\System\Project\Dind\Strategy\PhpStrategy;
use App\System\Project\Dind\Strategy\PrepareStage;
use App\System\Project\Dind\Strategy\RailpackStrategy;
use App\System\Project\Dind\Strategy\RubyStrategy;
use App\System\Project\Dind\Strategy\UserComposeStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which writer DeployStrategy::apply() hands each detected strategy to, with what, and
 * whether the prepare stage ran before it.
 */
class DeployStrategyApplyDispatchTest extends TestCase
{
    private const PROJECT = '/home/acme/project';

    private const CHOWN = '1000:1000';

    private const SOURCE = 'https://example.com/acme/app.git';

    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $calls = [];

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function strategies(): array
    {
        return [
            'compose' => [Strategies::COMPOSE, ['userCompose.apply']],
            'paemd' => [Strategies::PAEMD, ['userCompose.apply']],
            'dockerfile' => [Strategies::DOCKERFILE, ['prepare.manifestFor', 'prepare.run', 'dockerfile.apply']],
            'rails' => [Strategies::RAILS, ['prepare.manifestFor', 'prepare.run', 'ruby.apply']],
            'ruby' => [Strategies::RUBY, ['prepare.manifestFor', 'prepare.run', 'ruby.apply']],
            'laravel' => [Strategies::LARAVEL, ['prepare.manifestFor', 'prepare.run', 'php.apply']],
            'php' => [Strategies::PHP, ['prepare.manifestFor', 'prepare.run', 'php.apply']],
            'nextjs' => [Strategies::NEXTJS, ['prepare.manifestFor', 'prepare.run', 'framework.apply']],
            'python' => [Strategies::PYTHON, ['prepare.manifestFor', 'prepare.run', 'framework.apply']],
            'railpack' => [Strategies::RAILPACK, ['prepare.manifestFor', 'prepare.run', 'railpack.applyRailpackOrFallback']],
            'static' => [Strategies::STATIC, ['prepare.manifestFor', 'prepare.run', 'composeWriter.writeStaticNginxConf', 'composeWriter.writeGeneratedCompose']],
            'fallback' => [Strategies::FALLBACK, ['prepare.manifestFor', 'prepare.run', 'railpack.applyFallback']],
            'unknown' => ['something-else', ['prepare.manifestFor', 'prepare.run', 'railpack.applyFallback']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('strategies')]
    public function test_each_strategy_reaches_its_writer(string $strategy, array $expected): void
    {
        $this->strategy()->apply($this->decision($strategy), $this->appConfig(), self::PROJECT, self::CHOWN, self::SOURCE);

        $this->assertSame($expected, array_column($this->calls, 0));
    }

    public function test_every_writer_gets_the_arguments_it_always_got(): void
    {
        $config = $this->appConfig();
        $args = [];
        foreach (array_keys(self::strategies()) as $name) {
            $this->calls = [];
            $decision = $this->decision(self::strategies()[$name][0]);
            $this->strategy()->apply($decision, $config, self::PROJECT, self::CHOWN, self::SOURCE);
            $args[$name] = array_map(fn (array $call): array => [$call[0], $this->described($call[1], $decision, $config)], $this->calls);
        }

        $this->assertSame([
            'compose' => [['userCompose.apply', ['decision', 'config', self::PROJECT, self::CHOWN, self::SOURCE]]],
            'paemd' => [['userCompose.apply', ['decision', 'config', self::PROJECT, self::CHOWN, self::SOURCE]]],
            'dockerfile' => [['prepare.manifestFor', ['decision', 'config']], ['prepare.run', [self::PROJECT, 'manifest', 'config']], ['dockerfile.apply', ['decision', self::PROJECT, self::CHOWN]]],
            'rails' => [['prepare.manifestFor', ['decision', 'config']], ['prepare.run', [self::PROJECT, 'manifest', 'config']], ['ruby.apply', ['decision', self::PROJECT, self::CHOWN, 'config']]],
            'ruby' => [['prepare.manifestFor', ['decision', 'config']], ['prepare.run', [self::PROJECT, 'manifest', 'config']], ['ruby.apply', ['decision', self::PROJECT, self::CHOWN, 'config']]],
            'laravel' => [['prepare.manifestFor', ['decision', 'config']], ['prepare.run', [self::PROJECT, 'manifest', 'config']], ['php.apply', [self::PROJECT, self::CHOWN, true, 'config', 'decision']]],
            'php' => [['prepare.manifestFor', ['decision', 'config']], ['prepare.run', [self::PROJECT, 'manifest', 'config']], ['php.apply', [self::PROJECT, self::CHOWN, false, 'config', 'decision']]],
            'nextjs' => [['prepare.manifestFor', ['decision', 'config']], ['prepare.run', [self::PROJECT, 'manifest', 'config']], ['framework.apply', ['decision', self::PROJECT, self::CHOWN, 'config']]],
            'python' => [['prepare.manifestFor', ['decision', 'config']], ['prepare.run', [self::PROJECT, 'manifest', 'config']], ['framework.apply', ['decision', self::PROJECT, self::CHOWN, 'config']]],
            'railpack' => [['prepare.manifestFor', ['decision', 'config']], ['prepare.run', [self::PROJECT, 'manifest', 'config']], ['railpack.applyRailpackOrFallback', [self::PROJECT, self::CHOWN, self::SOURCE]]],
            'static' => [['prepare.manifestFor', ['decision', 'config']], ['prepare.run', [self::PROJECT, 'manifest', 'config']], ['composeWriter.writeStaticNginxConf', [self::PROJECT, 'about.html', self::CHOWN]], ['composeWriter.writeGeneratedCompose', [self::PROJECT, DeployCompose::staticNginx(), self::CHOWN]]],
            'fallback' => [['prepare.manifestFor', ['decision', 'config']], ['prepare.run', [self::PROJECT, 'manifest', 'config']], ['railpack.applyFallback', [self::PROJECT, self::CHOWN, self::SOURCE]]],
            'unknown' => [['prepare.manifestFor', ['decision', 'config']], ['prepare.run', [self::PROJECT, 'manifest', 'config']], ['railpack.applyFallback', [self::PROJECT, self::CHOWN, self::SOURCE]]],
        ], $args);
    }

    /** @return array<string, mixed> */
    private function decision(string $strategy): array
    {
        return [
            'strategy' => $strategy,
            'label' => 'Acme',
            'compose_path' => null,
            'dockerfile' => null,
            'port_hint' => 3000,
            'runtime' => null,
            'static_index' => 'about.html',
        ];
    }

    private function appConfig(): AppConfig
    {
        return (new \ReflectionClass(AppConfig::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param  list<mixed>  $args
     * @param  array<string, mixed>  $decision
     * @return list<mixed>
     */
    private function described(array $args, array $decision, AppConfig $config): array
    {
        return array_map(static fn (mixed $arg): mixed => match (true) {
            $arg === $decision => 'decision',
            $arg === $config => 'config',
            $arg instanceof PlatformManifest => 'manifest',
            default => $arg,
        }, $args);
    }

    private function strategy(): DeployStrategy
    {
        $manifest = (new \ReflectionClass(PlatformManifest::class))->newInstanceWithoutConstructor();
        $writer = $this->recording(ComposeWriter::class, 'composeWriter', ['writeStaticNginxConf', 'writeGeneratedCompose']);
        $dind = $this->createStub(Dind::class);
        $dind->method('composeWriter')->willReturn($writer);

        $strategy = new DeployStrategy($dind);
        $writers = [
            'userCompose' => [UserComposeStrategy::class, ['apply']],
            'dockerfile' => [DockerfileStrategy::class, ['apply']],
            'ruby' => [RubyStrategy::class, ['apply']],
            'php' => [PhpStrategy::class, ['apply']],
            'framework' => [FrameworkStrategy::class, ['apply']],
            'railpack' => [RailpackStrategy::class, ['applyRailpackOrFallback', 'applyFallback']],
        ];
        foreach ($writers as $property => [$class, $methods]) {
            $this->inject($strategy, $property, $this->recording($class, $property, $methods));
        }
        $prepare = $this->createStub(PrepareStage::class);
        $prepare->method('manifestFor')->willReturnCallback(function (mixed ...$args) use ($manifest): PlatformManifest {
            $this->calls[] = ['prepare.manifestFor', $args];

            return $manifest;
        });
        $prepare->method('run')->willReturnCallback(function (mixed ...$args): bool {
            $this->calls[] = ['prepare.run', $args];

            return true;
        });
        $this->inject($strategy, 'prepare', $prepare);

        return $strategy;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  list<string>  $methods
     * @return T
     */
    private function recording(string $class, string $name, array $methods): object
    {
        $mock = $this->createStub($class);
        foreach ($methods as $method) {
            $mock->method($method)->willReturnCallback(function (mixed ...$args) use ($name, $method): void {
                $this->calls[] = ["{$name}.{$method}", $args];
            });
        }

        return $mock;
    }

    private function inject(DeployStrategy $strategy, string $property, object $value): void
    {
        (new \ReflectionProperty(DeployStrategy::class, $property))->setValue($strategy, $value);
    }
}
