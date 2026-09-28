<?php

namespace Tests\Unit\System\Services;

use App\System;
use App\System\Services\Csf;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CsfRuleTest extends TestCase
{
    private const RULE = 'tcp|in|d=22|s=1.2.3.4 # ssh';

    /** A System whose csf.allow holds $contents and which records what gets written back. */
    private function system(string $contents): System
    {
        return new class ($contents) extends System {
            public ?string $written = null;

            public function __construct(public string $contents)
            {
            }

            public function execOnHost(string|array $cmd, array $env = []): string
            {
                return $this->contents;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                // ['sudo', 'cp', $tmpFile, $path]
                $this->written = (string)file_get_contents($cmd[2]);
                $process = Process::fromShellCommandline('true');
                $process->run();

                return $process;
            }
        };
    }

    public function test_an_edit_that_sends_only_target_and_comment_keeps_the_port_restriction(): void
    {
        $system = $this->system("1.1.1.1\n" . self::RULE . "\n");

        $rule = (new Csf($system))->editRule('allow', md5(self::RULE), [
            'target' => '5.6.7.8',
            'comment' => 'ssh from office',
        ]);

        $this->assertSame('tcp|in|d=22|s=5.6.7.8 # ssh from office', $rule['raw']);
        $this->assertSame("1.1.1.1\ntcp|in|d=22|s=5.6.7.8 # ssh from office\n", $system->written);
    }

    public function test_an_edit_keeps_the_comment_when_none_is_sent(): void
    {
        $system = $this->system(self::RULE);

        $rule = (new Csf($system))->editRule('allow', md5(self::RULE), [
            'target' => '1.2.3.4',
            'port' => '2222',
        ]);

        $this->assertSame('tcp|in|d=2222|s=1.2.3.4 # ssh', $rule['raw']);
    }

    public function test_fields_sent_as_null_are_cleared(): void
    {
        $system = $this->system(self::RULE);

        $rule = (new Csf($system))->editRule('allow', md5(self::RULE), [
            'target' => '1.2.3.4',
            'protocol' => null,
            'direction' => null,
            'port_prefix' => null,
            'port' => null,
            'target_prefix' => null,
            'comment' => null,
        ]);

        $this->assertSame('1.2.3.4', $rule['raw']);
    }

    public function test_clearing_one_port_field_is_refused_rather_than_widening_the_rule(): void
    {
        $system = $this->system(self::RULE);

        try {
            (new Csf($system))->editRule('allow', md5(self::RULE), ['target' => '1.2.3.4', 'port' => null]);
            $this->fail('A partial port rule was accepted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('port', $e->getMessage());
        }
        $this->assertNull($system->written);
    }

    public function test_a_partial_port_rule_is_refused_on_create(): void
    {
        $this->expectException(ValidationException::class);

        (new Csf($this->system('')))->unparseRule([
            'protocol' => 'tcp',
            'direction' => 'in',
            'port_prefix' => null,
            'port' => '22',
            'target_prefix' => null,
            'target' => '1.2.3.4',
            'comment' => null,
        ]);
    }

    public function test_an_unknown_rule_is_not_found(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new Csf($this->system(self::RULE)))->editRule('allow', md5('nope'), ['target' => '1.2.3.4']);
    }

    public function test_deleting_an_unknown_rule_is_not_found(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new Csf($this->system(self::RULE)))->deleteRule('allow', md5('nope'));
    }

    public function test_a_line_break_in_any_field_is_refused_before_anything_is_written(): void
    {
        $system = $this->system(self::RULE);

        foreach (['target' => "1.2.3.4\n0.0.0.0/0", 'comment' => "x\ntcp|in|d=3306|s=0.0.0.0/0"] as $field => $value) {
            try {
                (new Csf($system))->addRule('allow', [
                    'protocol' => null, 'direction' => null, 'port_prefix' => null, 'port' => null,
                    'target_prefix' => null, 'target' => '1.2.3.4', 'comment' => null, $field => $value,
                ]);
                $this->fail("{$field} with a line break was accepted");
            } catch (ValidationException) {
            }
        }
        $this->assertNull($system->written);
    }

    public function test_the_request_pins_target_port_and_comment_to_one_line(): void
    {
        $rules = (new \App\Http\Requests\Csf\AddRuleRequest())->rules();
        $bad = [
            ['target' => "1.2.3.4\n0.0.0.0/0"],
            ['target' => '1.2.3.4 # x'],
            ['target' => '1.2.3.4', 'port' => "22\n3306"],
            ['target' => '1.2.3.4', 'comment' => "ok\ntcp|in|d=3306|s=0.0.0.0/0"],
        ];
        foreach ($bad as $payload) {
            $this->assertTrue(\Illuminate\Support\Facades\Validator::make($payload, $rules)->fails(), json_encode($payload));
        }
        $good = [
            ['target' => '1.2.3.4'],
            ['target' => '10.0.0.0/8', 'comment' => 'office'],
            ['target' => '2001:db8::/32'],
            ['target' => 'backup.example.com'],
            ['target' => '1000', 'target_prefix' => 'u=', 'protocol' => 'tcp', 'direction' => 'out', 'port_prefix' => 'd=', 'port' => '80,443,2000_3000'],
        ];
        foreach ($good as $payload) {
            $this->assertTrue(\Illuminate\Support\Facades\Validator::make($payload, $rules)->passes(), json_encode($payload));
        }
    }
}
