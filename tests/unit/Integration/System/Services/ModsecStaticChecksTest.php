<?php

namespace Tests\Integration\System\Services;

use App\System\Services\Modsec;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** The verdicts the static rule checks and the scratch check give, word for word. */
class ModsecStaticChecksTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string}> */
    public static function rules(): array
    {
        return [
            'empty' => ['', null],
            'only comments' => ["# hello\n# world", null],
            'too large' => [str_repeat('#', 262145), 'The rules are larger than 256 KB.'],
            'exactly the limit' => [str_repeat('#', 262144), null],
            'rule engine in a comment' => ["# SecRuleEngine Off", 'SecRuleEngine and ctl:ruleEngine are not accepted in custom rules, in any spelling, not even in a comment or a message. The engine\'s mode is the ModSecurity setting for every site; a rule that changes it switches protection off or to detection-only. To exempt requests from a rule, use ctl:ruleRemoveById.'],
            'rule engine joined by a continuation' => ["SecAction \"id:1100001,ctl:rule\\\n  Engine=Off\"", 'SecRuleEngine and ctl:ruleEngine are not accepted in custom rules, in any spelling, not even in a comment or a message. The engine\'s mode is the ModSecurity setting for every site; a rule that changes it switches protection off or to detection-only. To exempt requests from a rule, use ctl:ruleRemoveById.'],
            'rule engine in a message' => ["SecAction \"id:1100014,msg:'RuleEngine'\"", 'SecRuleEngine and ctl:ruleEngine are not accepted in custom rules, in any spelling, not even in a comment or a message. The engine\'s mode is the ModSecurity setting for every site; a rule that changes it switches protection off or to detection-only. To exempt requests from a rule, use ctl:ruleRemoveById.'],
            'commented-out rule swallowing a directive' => ["# SecRule ARGS \"@rx a\" \\\nSecRule ARGS \"@rx b\" \"id:1100001,deny\"", 'Line 1 is a commented-out SecRule/SecAction that ends in \\. ModSecurity reads line 2 as part of the comment, so that rule would never load. Comment that line too, or remove the backslash.'],
            'commented-out rule swallowing comments and a blank' => ["# SecRule ARGS \\\n# more \\\n\n# last\nSecRule ARGS \"@rx b\" \"id:1100002,deny\"", null],
            'commented-out rule swallowing a directive after a blank' => ["# SecAction \\\n\n   \nSecMarker END", 'Line 1 is a commented-out SecRule/SecAction that ends in \\. ModSecurity reads line 4 as part of the comment, so that rule would never load. Comment that line too, or remove the backslash.'],
            'commented-out rule at the end' => ["SecMarker A\n# SecAction \\", null],
            'comment ending in a backslash right after the name' => ["# SecRule\\\nSecMarker A", null],
            'ordinary comment ending in a backslash' => ["# note \\\nSecMarker A", null],
            'backslash after a complete directive' => ["SecRule ARGS \"@rx a\" \"id:1100001,deny\" \\\nSecRule ARGS \"@rx b\" \"id:1100002,deny\"", 'Line 1 ends in \\ after a complete directive. ModSecurity does not carry a finished directive onto the next line; it reads that line as a directive of its own. Remove the backslash.'],
            'continuation joined' => ["SecRule ARGS \\\n  \"@rx a\" \\\n  \"id:1100001,deny\"", null],
            'continuation inside an open quote' => ["SecAction \"id:1100016,\\\npass\"", null],
            'lone backslash then a blank line' => ["\\\n", null],
            'backslash on the last line' => ["SecRule ARGS \\", null],
            'quoted directive' => ["\"foo\" bar", 'A directive must not be quoted.'],
            'second directive on a line' => ["SecRule ARGS \"@rx a\" \"id:1100001,deny\" SecAction \"id:1100002\"", 'A line may hold only one directive, at its start. Put each SecRule, SecAction or SecMarker on its own line (a chained rule is several lines, each a SecRule). A directive name may not appear anywhere else on the line, not even inside quotes: "SecRule ARGS "@rx a" "id:1100001,deny" SecAction "id:1100002""'],
            'glued directive' => ["SecMarker A\"SecAction\"", 'A line may hold only one directive, at its start. Put each SecRule, SecAction or SecMarker on its own line (a chained rule is several lines, each a SecRule). A directive name may not appear anywhere else on the line, not even inside quotes: "SecMarker A"SecAction""'],
            'directive name in the data' => ["SecRule ARGS \"@contains secaction\" \"id:1100017,deny\"", 'A line may hold only one directive, at its start. Put each SecRule, SecAction or SecMarker on its own line (a chained rule is several lines, each a SecRule). A directive name may not appear anywhere else on the line, not even inside quotes: "SecRule ARGS "@contains secaction" "id:1100017,deny""'],
            'include' => ['Include /etc/passwd', 'Only rule directives are accepted (SecRule, SecAction, SecMarker, SecRuleRemoveBy*, SecRuleUpdate*By*), not include.'],
            'unknown directive continued' => ["Foo bar \\\nbaz", 'Only rule directives are accepted (SecRule, SecAction, SecMarker, SecRuleRemoveBy*, SecRuleUpdate*By*), not foo.'],
            'lowercase directive' => ['secmarker A', null],
            'marker with an extra word' => ['SecMarker END extra', 'There is more on this line than its directive takes, and ModSecurity would read the rest as another directive. Only a # comment may follow the last argument: "SecMarker END extra"'],
            'marker with a trailing comment' => ['SecMarker END # note', null],
            'remove by id takes the rest' => ['SecRuleRemoveById 1100001 1100002', null],
            'remove by msg with two quoted values' => ['SecRuleRemoveByMsg "a" "b"', 'There is more on this line than its directive takes, and ModSecurity would read the rest as another directive. Only a # comment may follow the last argument: "SecRuleRemoveByMsg "a" "b""'],
            'remove by msg with one quoted value' => ['SecRuleRemoveByMsg "a b"', null],
            'update target takes two' => ['SecRuleUpdateTargetById 1100001 "!ARGS:x" extra', 'There is more on this line than its directive takes, and ModSecurity would read the rest as another directive. Only a # comment may follow the last argument: "SecRuleUpdateTargetById 1100001 "!ARGS:x" extra"'],
            'id out of range' => ['SecAction "id:42,pass"', 'Rule id 42 is outside the range kept for custom rules, 1100000-1199999.'],
            'id at the top of the range' => ['SecAction "id:1199999,pass"', null],
            'id above the range' => ['SecAction "id:1200000,pass"', 'Rule id 1200000 is outside the range kept for custom rules, 1100000-1199999.'],
            'id written spaced and quoted' => ["SecAction \"id : '1100020',pass\"", null],
            'duplicate id' => ["SecAction \"id:1100001,pass\"\nSecAction \"id:1100001,pass\"", 'Rule id 1100001 is used twice.'],
            'id inside a message' => ["SecAction \"id:1100001,msg:'see id:5'\"", 'Rule id 5 is outside the range kept for custom rules, 1100000-1199999.'],
            'bare token with an escaped quote' => ["SecRule ARGS:a\\\"b \"@rx x\" \"id:1100003,deny\"", null],
            'quoted value with escaped quotes' => ["SecAction \"id:1100015,msg:'a \\\"b\\\"'\"", null],
            'rule with two arguments' => ['SecRule ARGS', null],
            'rule with no operator named' => ['SecRule ARGS "foo" "id:1100004,deny"', null],
            'space before the at' => ['SecRule ARGS " @rx x" "id:1100005,deny"', 'The operator " @rx x" has a space before the @, so ModSecurity would read it as a regular expression, not as an operator. To match a literal @, use @rx.'],
            'negated operator' => ['SecRule ARGS "!@streq x" "id:1100006,deny"', null],
            'unknown operator' => ['SecRule ARGS "@foo x" "id:1100007,deny"', 'Unknown operator @foo. ModSecurity would read "@foo x" as a regular expression, so the rule would not do what it says. To match a literal @, use @rx.'],
            'operator in another case' => ['SecRule ARGS "@BeginsWith /x" "id:1100008,deny"', null],
            'no-argument operator with an argument' => ['SecRule ARGS "@detectSQLi x" "id:1100009,deny"', 'The operator @detectSQLi takes no argument. ModSecurity would read "@detectSQLi x" as a regular expression, so the rule would not do what it says.'],
            'no-argument operator with spaces' => ['SecRule ARGS "@detectSQLi  " "id:1100010,deny"', null],
            'no-argument operator unquoted' => ['SecRule ARGS @detectSQLi "id:1100011,deny"', null],
            'skip' => ['SecRule ARGS "@rx a" "id:1100012,deny,skip:1"', 'skip is not accepted in custom rules: it counts the rules ModSecurity runs next, in every rule set and into later phases. Use skipAfter with a SecMarker after the rules to skip.'],
            'skip spaced' => ['SecRule ARGS "@rx a" "id:1100012,deny,skip :1"', 'skip is not accepted in custom rules: it counts the rules ModSecurity runs next, in every rule set and into later phases. Use skipAfter with a SecMarker after the rules to skip.'],
            'skipAfter on a marker' => ['SecMarker skipAfter:X', 'skipAfter is accepted only in a SecRule or SecAction, before the SecMarker it names.'],
            'skipAfter before its marker' => ["SecAction \"id:1100013,pass,skipAfter:END\"\nSecMarker END", null],
            'skipAfter after its marker' => ["SecMarker END\nSecAction \"id:1100013,pass,skipAfter:END\"", 'skipAfter:END names no SecMarker after it in these rules (names are case-sensitive). ModSecurity would skip rules outside these, in every rule set and in later phases, for every request it matches. Put SecMarker END after the rules it should skip.'],
            'skipAfter to a quoted marker' => ["SecAction \"id:1100013,pass,skipAfter:'END'\"\nSecMarker 'END'", null],
            'skipAfter misspelt' => ["SecAction \"id:1100013,pass,skipAfter:END\"\nSecMarker END2", 'skipAfter:END names no SecMarker after it in these rules (names are case-sensitive). ModSecurity would skip rules outside these, in every rule set and in later phases, for every request it matches. Put SecMarker END after the rules it should skip.'],
            'skipAfter case differs' => ["SecAction \"id:1100013,pass,skipAfter:End\"\nSecMarker END", 'skipAfter:End names no SecMarker after it in these rules (names are case-sensitive). ModSecurity would skip rules outside these, in every rule set and in later phases, for every request it matches. Put SecMarker End after the rules it should skip.'],
            'two skipAfters one missing' => ["SecAction \"id:1100013,pass,skipAfter:A,skipAfter:B\"\nSecMarker A", 'skipAfter:B names no SecMarker after it in these rules (names are case-sensitive). ModSecurity would skip rules outside these, in every rule set and in later phases, for every request it matches. Put SecMarker B after the rules it should skip.'],
            'skipAfter on a rule and a later rule' => ["SecRule ARGS \"@rx a\" \"id:1100021,pass,skipAfter:M\"\nSecRule ARGS \"@rx b\" \"id:1100022,deny\"\nSecMarker M", null],
            'crlf lines' => ["SecMarker A\r\nSecMarker B", null],
            'tabs between arguments' => ["SecRule\tARGS\t\"@rx a\"\t\"id:1100023,deny\"", null],
            'trailing comment after a rule' => ['SecRule REQUEST_URI "@beginsWith /x" "id:1100018,phase:1,deny" # trailing', null],
            'chained rule' => ["SecRule ARGS \"@rx a\" \"id:1100024,deny,chain\"\nSecRule ARGS \"@rx b\"", null],
        ];
    }

    #[DataProvider('rules')]
    public function test_the_static_checks_answer(string $rules, ?string $problem): void
    {
        $this->assertSame($problem, Modsec::customRulesProblem($rules));
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function checkAnswers(): array
    {
        $a = static function (array $changed = [], array $drop = [], string $extra = ''): string {
            $answers = array_merge(['first' => '418', 'phase1' => '418', 'phase2' => '418', 'phase3' => '418', 'body' => '418', 'plain' => '204'], $changed);
            $lines = [];
            foreach ($answers as $request => $code) {
                if (! in_array($request, $drop, true)) {
                    $lines[] = "modsecurity-check {$request} {$code}";
                }
            }

            return $extra . implode("\n", $lines) . "\n";
        };

        return [
            'every answer as it should be' => [$a(), null],
            'with nginx noise around it' => [$a([], [], "nginx: [warn] something\n"), null],
            'nothing answered' => ['', 'the check of what the rules do could not run, so they are not applied'],
            'one request missing' => [$a([], ['phase3']), 'the check of what the rules do could not run, so they are not applied'],
            'one request not answered' => [$a(['body' => '000']), 'the check of what the rules do could not run, so they are not applied'],
            'the first rule did not deny' => [$a(['first' => '204']), 'with these rules in place, a request that a rule loaded before them denies gets through. They would switch ModSecurity off or to detection-only, or remove the rules before them, for every site.'],
            'the first rule answered something else' => [$a(['first' => '403']), 'with these rules in place, a request that a rule loaded before them denies gets through. They would switch ModSecurity off or to detection-only, or remove the rules before them, for every site.'],
            'phase 1 got through' => [$a(['phase1' => '204']), 'with these rules in place, a request that a phase 1 rule loaded after them denies gets through. They would stop the later rules in phase 1, for every site (an allow, a skip or skipAfter, or a ctl that matches every request).'],
            'phase 2 got through' => [$a(['phase2' => '204']), 'with these rules in place, a request that a phase 2 rule loaded after them denies gets through. They would stop the later rules in phase 2, for every site (an allow, a skip or skipAfter, or a ctl that matches every request).'],
            'phase 3 got through' => [$a(['phase3' => '204']), 'with these rules in place, a request that a phase 3 rule loaded after them denies gets through. They would stop the later rules in phase 3, for every site (an allow, a skip or skipAfter, or a ctl that matches every request).'],
            'phases 2 and 3 got through' => [$a(['phase2' => '204', 'phase3' => '204']), 'with these rules in place, a request that a phase 2 rule loaded after them denies gets through. They would stop the later rules in phase 2, for every site (an allow, a skip or skipAfter, or a ctl that matches every request).'],
            'a phase answered something else' => [$a(['phase2' => '403']), null],
            'the body got through' => [$a(['body' => '204']), 'with these rules in place, a request body that a rule loaded after them denies gets through. They would stop ModSecurity reading request bodies, for every site (a ctl that matches every request).'],
            'the plain request was denied' => [$a(['plain' => '403']), 'with these rules in place, an ordinary request with nothing in it to block is answered 403 instead of reaching the site. These rules would deny ordinary requests, on every site.'],
            'the plain request was the check status' => [$a(['plain' => '418']), 'with these rules in place, an ordinary request with nothing in it to block is answered 418 instead of reaching the site. These rules would deny ordinary requests, on every site.'],
            'a later answer for the same request wins' => [$a() . "modsecurity-check first 204\n", 'with these rules in place, a request that a rule loaded before them denies gets through. They would switch ModSecurity off or to detection-only, or remove the rules before them, for every site.'],
            'an answer with a suffix is not read' => [str_replace('modsecurity-check plain 204', 'modsecurity-check plain 2045', $a()), 'the check of what the rules do could not run, so they are not applied'],
        ];
    }

    #[DataProvider('checkAnswers')]
    public function test_the_check_reads_its_answers(string $output, ?string $problem): void
    {
        $this->assertSame($problem, Modsec::checkProblem($output));
    }

    public function test_the_apache_scratch_config(): void
    {
        $this->assertSame(implode("\n", [
            'ServerRoot "/usr/local/apache2"',
            'ServerName localhost',
            'Listen ' . TestNetwork::LOOPBACK . ':65535',
            'LoadModule mpm_event_module modules/mod_mpm_event.so',
            'LoadModule authz_core_module modules/mod_authz_core.so',
            'LoadModule unixd_module modules/mod_unixd.so',
            'LoadModule security3_module /usr/lib/apache2/modules/mod_security3.so',
            'ErrorLog /dev/stderr',
            'modsecurity on',
            'modsecurity_rules_file /opt/x/.custom-rules-test-abc/main.conf',
            '',
        ]), Modsec::configTestConfig('apache', '/opt/x/.custom-rules-test-abc'));
    }

    /** Every webserver but apache gets the nginx one. */
    public function test_the_nginx_scratch_config(): void
    {
        $expected = implode("\n", [
            'load_module modules/ngx_http_modsecurity_module.so;',
            'daemon off;',
            'master_process off;',
            'pid /opt/x/.custom-rules-test-abc/nginx.pid;',
            'lock_file /opt/x/.custom-rules-test-abc/nginx.lock;',
            'error_log stderr;',
            'events {}',
            'http {',
            '    client_body_temp_path /opt/x/.custom-rules-test-abc/temp;',
            '    proxy_temp_path /opt/x/.custom-rules-test-abc/temp;',
            '    fastcgi_temp_path /opt/x/.custom-rules-test-abc/temp;',
            '    uwsgi_temp_path /opt/x/.custom-rules-test-abc/temp;',
            '    scgi_temp_path /opt/x/.custom-rules-test-abc/temp;',
            '    access_log off;',
            '    modsecurity on;',
            '    modsecurity_rules_file /opt/x/.custom-rules-test-abc/check.conf;',
            '    server {',
            '        listen unix:/opt/x/.custom-rules-test-abc/check.sock;',
            '        location / {',
            '            root /opt/x/.custom-rules-test-abc/www;',
            '            try_files $uri =204;',
            '        }',
            '    }',
            '}',
            '',
        ]);

        foreach (['nginx', 'nginx-proxy', 'litespeed', ''] as $webserver) {
            $this->assertSame($expected, Modsec::configTestConfig($webserver, '/opt/x/.custom-rules-test-abc'), $webserver);
        }
    }
}
