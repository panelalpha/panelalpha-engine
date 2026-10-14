<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\GitRefs;
use PHPUnit\Framework\TestCase;

/**
 * `git ls-remote --symref -- <url> HEAD refs/heads/* refs/tags/*`, and what the
 * probe reads out of it. The same text decides a branch problem and the refs
 * list, so both read it here.
 */
class GitRefsTest extends TestCase
{
    private const REFS = "ref: refs/heads/main\tHEAD\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\tHEAD\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\trefs/heads/feature/nested\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\trefs/heads/main\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\trefs/tags/v1.0\n"
        . "0dfb2685d04204d4232f1075904de619b900fcb0\trefs/tags/v2.0\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\trefs/tags/v2.0^{}\n";

    public function test_it_reads_the_default_branch_the_heads_and_the_tags(): void
    {
        $refs = GitRefs::parse(self::REFS);

        $this->assertSame('main', $refs->defaultBranch);
        $this->assertSame(['feature/nested', 'main'], $refs->branches);
        $this->assertSame(['v1.0', 'v2.0'], $refs->tags);
    }

    public function test_a_peeled_tag_is_the_same_tag(): void
    {
        $sha = str_repeat('a', 40);
        $tags = GitRefs::parse("{$sha}\trefs/tags/v2.0\n{$sha}\trefs/tags/v2.0^{}\n")->tags;

        $this->assertSame(['v2.0'], $tags);
    }

    /** A numeric tag stays a string, so it is listed as one and found by name. */
    public function test_a_numeric_tag_stays_a_string(): void
    {
        $sha = str_repeat('a', 40);
        $refs = GitRefs::parse("{$sha}\trefs/tags/2024\n{$sha}\trefs/tags/2024^{}\n{$sha}\trefs/tags/v1\n");

        $this->assertSame(['2024', 'v1'], $refs->tags);
        $this->assertTrue($refs->hasBranch('2024'));
    }

    public function test_a_branch_or_tag_is_found(): void
    {
        $refs = GitRefs::parse(self::REFS);

        $this->assertTrue($refs->hasBranch('main'));
        $this->assertTrue($refs->hasBranch('feature/nested'));
        $this->assertTrue($refs->hasBranch('v1.0'));
        $this->assertFalse($refs->hasBranch('develop'));
    }

    public function test_branches_lead_with_the_default_then_go_by_name(): void
    {
        $this->assertSame(['main', 'feature/nested'], GitRefs::parse(self::REFS)->sortedBranches());
    }

    /** A remote that names no default still gets its branches back, by name. */
    public function test_without_a_default_the_branches_are_just_sorted(): void
    {
        $sha = str_repeat('a', 40);
        $branches = GitRefs::parse("{$sha}\trefs/heads/main\n{$sha}\trefs/heads/develop\n")->sortedBranches();

        $this->assertSame(['develop', 'main'], $branches);
    }

    public function test_tags_are_sorted_by_name(): void
    {
        $this->assertSame(['v1.0', 'v2.0'], GitRefs::parse(self::REFS)->sortedTags());
    }

    /** A SHA-256 remote (64 hex) is read, and other lines are ignored. */
    public function test_a_sha256_remote_is_read(): void
    {
        $sha = str_repeat('a', 64);
        $refs = GitRefs::parse("ref: refs/heads/trunk\tHEAD\n{$sha}\trefs/heads/trunk\nnot a ref line\n");

        $this->assertSame('trunk', $refs->defaultBranch);
        $this->assertSame(['trunk'], $refs->branches);
        $this->assertSame([], $refs->tags);
    }

    public function test_no_output_is_no_refs(): void
    {
        $refs = GitRefs::parse('');

        $this->assertNull($refs->defaultBranch);
        $this->assertSame([], $refs->branches);
        $this->assertSame([], $refs->tags);
    }
}
