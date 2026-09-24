<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\PrivateRepoMask;
use App\Lib\Deploy\Telemetry\Redactor;
use PHPUnit\Framework\TestCase;

/**
 * The report hashes a private repository, and that hash is worthless if the
 * clone line next to it names the repository in the clear.
 */
class PrivateRepoMaskTest extends TestCase
{
    private const URL = 'https://github.com/skinkaidbwf/carryduff-astro';

    public function test_nothing_is_masked_for_a_public_repository(): void
    {
        $this->assertNull(PrivateRepoMask::for(false, [self::URL], ['main']));
    }

    public function test_nothing_is_masked_without_a_repository_to_name(): void
    {
        $this->assertNull(PrivateRepoMask::for(true, [null, ''], ['main']));
        $this->assertNull(PrivateRepoMask::for(true, ['not a url'], ['main']));
    }

    /**
     * The lines a rejected token produces, from a real report: the engine's
     * clone line and git's own error.
     */
    public function test_the_clone_line_and_gits_error_lose_the_repository_and_branch(): void
    {
        $mask = PrivateRepoMask::for(true, [self::URL], ['main']);

        $this->assertSame(
            'Cloning repository https://github.com/<repo> (branch: <branch>)',
            Redactor::line('Cloning repository ' . self::URL . ' (branch: main)', 'carryduff', repo: $mask)
        );
        $this->assertSame(
            "fatal: Authentication failed for 'https://github.com/<repo>/'",
            Redactor::line("fatal: Authentication failed for '" . self::URL . "/'", 'carryduff', repo: $mask)
        );
    }

    /** Masked before the account name, which is part of the repository's here. */
    public function test_a_repository_named_after_the_account_is_still_masked(): void
    {
        $mask = PrivateRepoMask::for(true, [self::URL]);
        $line = Redactor::line('remote: ' . self::URL . '.git', 'carryduff', repo: $mask);

        $this->assertStringNotContainsString('skinkaidbwf', $line);
        $this->assertStringNotContainsString('astro', $line);
    }

    public function test_every_spelling_of_the_repository_is_masked(): void
    {
        $mask = PrivateRepoMask::for(true, ['https://gitlab.com/Acme/Internal-CRM.git']);

        foreach ([
            'https://gitlab.com/acme/internal-crm.git',
            'git@gitlab.com:acme/internal-crm.git',
            'https://x-access-token:***@gitlab.com/acme/internal-crm',
            "ERROR: Repository 'acme/internal-crm' not found",
        ] as $line) {
            $this->assertStringNotContainsStringIgnoringCase('internal-crm', $mask->apply($line), $line);
        }
    }

    public function test_a_longer_name_sharing_the_prefix_is_a_different_repository(): void
    {
        $mask = PrivateRepoMask::for(true, ['https://github.com/acme/crm']);

        $this->assertSame('acme/crm-docs', $mask->apply('acme/crm-docs'));
    }

    /** `main` is a word as well as a branch; only the branch is masked. */
    public function test_the_branch_is_masked_only_where_the_text_calls_it_a_branch(): void
    {
        $mask = PrivateRepoMask::for(true, [self::URL], ['main']);

        $this->assertSame(
            'fatal: Remote branch <branch> not found in upstream origin',
            $mask->apply('fatal: Remote branch main not found in upstream origin')
        );
        $this->assertSame('Cannot find module ./main.js', $mask->apply('Cannot find module ./main.js'));
    }

    /** One mask for every install, so a failure still groups across them. */
    public function test_the_mask_does_not_depend_on_the_repository(): void
    {
        $a = PrivateRepoMask::for(true, ['https://github.com/one/app'])->apply('https://github.com/one/app');
        $b = PrivateRepoMask::for(true, ['https://github.com/two/site'])->apply('https://github.com/two/site');

        $this->assertSame($a, $b);
    }
}
