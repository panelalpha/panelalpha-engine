<?php

namespace Tests;

use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Noodlehaus\Config;
use ReflectionClass;
use ReflectionMethod;
use Tests\Attributes\SetsCache;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    public string $testingSessionName = "default";
    private string $cacheFile;
    private Config $cache;
    private bool $skipOnMissingCache = false;

    /** @var array<string, string>|null cache key => Class::method */
    private static ?array $cacheProducers = null;

    public static function getFromCache(string $name): mixed
    {
        $testingSessionName = (string)env('TESTING_SESSION', 'default');
        $cacheFile = __DIR__ . "/sessions/{$testingSessionName}/cache.json";
        if (!file_exists($cacheFile)) {
            return null;
        }
        $cache = Config::load($cacheFile);
        return $cache->get($name);
    }

    protected function setUp(): void
    {
        $testingSessionName = (string)env('TESTING_SESSION', 'default');
        $sessionDir = __DIR__ . "/sessions/{$testingSessionName}";
        if (!file_exists($sessionDir)) {
            mkdir($sessionDir, 0755, true);
        }
        if (!file_exists("$sessionDir/.env")) {
            touch("$sessionDir/.env");
        }
        $dotenv = Dotenv::createImmutable($sessionDir);
        $dotenv->load();
        $this->cacheFile = "$sessionDir/cache.json";
        if (!file_exists($this->cacheFile)) {
            file_put_contents($this->cacheFile, '[]');
        }
        $this->cache = Config::load($this->cacheFile);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->cache->toFile($this->cacheFile);
        parent::tearDown();
    }

    public function getCacheAsString(string $key): string
    {
        $value = $this->getCache($key);
        if (!is_string($value)) {
            $this->fail("Invalid cache `{$key}`, expected string");
        }
        return $value;
    }

    public function getCache(string $key): mixed
    {
        $parts = explode('.', $key);
        $keyPart = array_shift($parts);
        do {
            if (!$this->cache->has($keyPart) || $this->cache->get($keyPart) === null) {
                $message = $this->missingCacheMessage($key, $keyPart);
                if ($this->skipOnMissingCache) {
                    $this->markTestSkipped($message);
                }
                $this->fail($message);
            }
            if (empty($parts)) {
                break;
            }
            $keyPart .= "." . array_shift($parts);
        } while (!empty($parts));
        return $this->cache->get($key);
    }

    private function missingCacheMessage(string $requestedKey, string $missingPart): string
    {
        $message = "Missing cache `{$requestedKey}`";
        $producer = $this->cacheProducerFor($missingPart) ?? $this->cacheProducerFor($requestedKey);
        if ($producer !== null) {
            $message .= " (set by {$producer})";
        }
        return $message;
    }

    private function cacheProducerFor(string $key): ?string
    {
        $root = explode('.', $key, 2)[0];
        $producers = self::cacheProducers();
        return $producers[$key] ?? $producers[$root] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private static function cacheProducers(): array
    {
        if (self::$cacheProducers !== null) {
            return self::$cacheProducers;
        }

        self::$cacheProducers = [];
        $featureDir = __DIR__ . '/Feature';
        if (!is_dir($featureDir)) {
            return self::$cacheProducers;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($featureDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }
            $class = 'Tests\\Feature\\' . substr($file->getFilename(), 0, -4);
            // Nested dirs under Feature are uncommon; keep flat mapping by filename.
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                foreach ($method->getAttributes(SetsCache::class) as $attribute) {
                    /** @var SetsCache $setsCache */
                    $setsCache = $attribute->newInstance();
                    self::$cacheProducers[$setsCache->key] = $class . '::' . $method->getName();
                }
            }
        }

        return self::$cacheProducers;
    }

    public function setCache(string $key, mixed $value): void
    {
        $this->cache->set($key, $value);
        $this->cache->toFile($this->cacheFile);
    }

    public function unsetCache(string $key): void
    {
        $this->cache->set($key, null);
    }

    public function hasCache(string $key): bool
    {
        return $this->cache->has($key) && ($this->cache->get($key) !== null);
    }

    public function skipIfCached(string $key): void
    {
        if (env('IGNORE_CACHE')) {
            return;
        }
        if ($this->hasCache($key)) {
            $this->markTestSkipped("`{$key}` already exists in this testing session");
        }
    }

    public function authenticate(): void
    {
        $token = $this->getCacheAsString('api_token');
        $this->withHeader('Authorization', "Bearer {$token}");
    }
}
