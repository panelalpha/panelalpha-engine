<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pomerium's build downloads an Envoy binary, so the recipe runs the published
 * image with one route from the site's address to a placeholder.
 */
class PomeriumSourceRecipeTest extends TestCase
{
    private const URL = 'https://github.com/pomerium/pomerium';

    public function test_the_route_goes_from_the_public_address_to_upstream(): void
    {
        $dir = SourceRecipes::directoryFor(self::URL);
        $this->assertNotNull($dir);
        $config = AppConfigDirectory::read(new LocalAppConfigSource(), $dir, true);
        $this->assertNotNull($config);
        $compose = Yaml::parse((string) $config->replacingCompose());

        $pomerium = $compose['services']['pomerium'];
        $this->assertStringStartsWith('pomerium/pomerium:v0.33.4@sha256:', $pomerium['image']);
        $this->assertSame('true', $pomerium['environment']['INSECURE_SERVER']);
        $this->assertSame('service_completed_successfully', $pomerium['depends_on']['config']['condition']);

        $writer = $compose['services']['config'];
        $this->assertSame('${PA_PUBLIC_URL}', $writer['environment']['FROM_URL']);
        $this->assertSame('${UPSTREAM:-http://backend:80}', $writer['environment']['TO_URL']);

        // What the writer produces, with compose's $$ unescaped.
        $script = str_replace('$$', '$', $writer['entrypoint'][2]);
        $yaml = shell_exec('FROM_URL=https://site.example TO_URL=http://backend:80 sh -c ' . escapeshellarg(
            str_replace('/pomerium/config.yaml', '/dev/stdout', $script)
        ));
        $this->assertSame([[
            'from' => 'https://site.example',
            'to' => 'http://backend:80',
            'allow_any_authenticated_user' => true,
        ]], Yaml::parse((string) $yaml)['routes']);
    }

    /** New secrets would sign everyone out on every redeploy. */
    public function test_the_secrets_are_generated_once_and_are_32_bytes(): void
    {
        $home = sys_get_temp_dir() . '/pa-pomerium-' . bin2hex(random_bytes(4));
        mkdir($home);
        $hook = SourceRecipes::directoryFor(self::URL) . '/hooks/prepare.sh';

        $seen = [];
        foreach ([1, 2] as $run) {
            exec('HOME=' . escapeshellarg($home) . ' bash ' . escapeshellarg($hook) . ' 2>&1', $output, $status);
            $this->assertSame(0, $status, implode("\n", $output));
            $seen[] = (string) file_get_contents($home . '/.panelalpha/pomerium/secrets.env');
        }
        exec('rm -rf ' . escapeshellarg($home));

        $this->assertSame($seen[0], $seen[1]);
        preg_match_all('/^(SHARED_SECRET|COOKIE_SECRET)=(\S+)$/m', $seen[0], $m);
        $this->assertSame(['SHARED_SECRET', 'COOKIE_SECRET'], $m[1]);
        foreach ($m[2] as $secret) {
            $this->assertSame(32, strlen((string) base64_decode($secret, true)));
        }
    }
}
