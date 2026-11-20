<?php

namespace Tests\Integration\Deploy\Sidecar;

use App\Lib\Deploy\Sidecar\EnvSidecars;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

class EnvSidecarsTest extends TestCase
{
    /**
     * Split Pro's .env.example points at `splitpro-db`, ByteStash's at `postgres`, Databasement's DB_HOST at
     * `mysql`: compose service names, so the Dockerfile's app waited for a database nothing ran.
     */
    public function test_a_compose_service_name_as_host_becomes_a_sidecar(): void
    {
        $url = EnvSidecars::fromVariables(['DATABASE_URL' => 'postgres://bytestash:bytestash@postgres:5432/bytestash']);
        $laravel = EnvSidecars::fromVariables([
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'mysql', 'DB_DATABASE' => 'databasement', 'DB_USERNAME' => 'app',
        ]);
        $dashed = EnvSidecars::fromVariables(['DATABASE_URL' => 'postgresql://postgres:pw@splitpro-db:5432/splitpro']);

        $this->assertSame('postgres://bytestash:bytestash@db:5432/bytestash', $url['env']['DATABASE_URL']);
        $this->assertSame('databasement', $laravel['services']['db']['environment']['MYSQL_DATABASE']);
        $this->assertSame('db', $laravel['env']['DB_HOST']);
        $this->assertSame('postgres://postgres:pw@db:5432/splitpro', $dashed['env']['DATABASE_URL']);
    }

    public function test_an_address_that_is_neither_local_nor_a_service_name_is_left_alone(): void
    {
        $this->assertSame([], EnvSidecars::fromVariables(['DATABASE_URL' => 'postgres://u:p@'.TestNetwork::private(7).':5432/app'])['services']);
        $this->assertSame([], EnvSidecars::fromVariables(['DATABASE_URL' => 'postgres://u:p@[fd00::7]:5432/app'])['services']);
        $this->assertSame([], EnvSidecars::fromVariables(['DATABASE_URL' => 'postgres://u:p@db.internal:5432/app'])['services']);
    }

    public function test_from_project_dir_reads_env_example(): void
    {
        $dir = sys_get_temp_dir().'/env-sidecars-'.uniqid('', true);
        mkdir($dir);
        try {
            file_put_contents(
                $dir.'/.env.example',
                "DATABASE_URL=postgres://localhost:5432/from_example\n"
            );

            $result = EnvSidecars::fromProjectDir($dir);

            $this->assertSame(
                'postgres://app:app@db:5432/from_example',
                $result['env']['DATABASE_URL']
            );
        } finally {
            @unlink($dir.'/.env.example');
            @rmdir($dir);
        }
    }

    public function test_live_env_overrides_example(): void
    {
        $dir = sys_get_temp_dir().'/env-sidecars-'.uniqid('', true);
        mkdir($dir);
        try {
            file_put_contents($dir.'/.env.example', "DATABASE_URL=postgres://localhost:5432/example\n");
            file_put_contents($dir.'/.env', "DATABASE_URL=postgres://localhost:5432/live\n");

            $result = EnvSidecars::fromProjectDir($dir);

            $this->assertStringContainsString('/live', $result['env']['DATABASE_URL']);
        } finally {
            @unlink($dir.'/.env');
            @unlink($dir.'/.env.example');
            @rmdir($dir);
        }
    }
}
