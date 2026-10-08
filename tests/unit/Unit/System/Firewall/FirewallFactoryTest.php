<?php

namespace Tests\Unit\System\Firewall;

use App\Http\Controllers\FirewallController;
use App\System\Firewall\Firewall;
use App\System\Firewall\FirewallException;
use App\System\Firewall\FirewallFactory;
use App\System\Firewall\FirewallRule;
use App\System\Firewall\FirewallNotFound;
use App\System\Firewall\FirewallStatus;
use App\System\Firewall\TrustedAddress;
use App\System\Firewall\Ufw\UfwFirewall;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** The API reaches a provider only through the factory, and guards the engine's own rules. */
class FirewallFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        FirewallFactory::reset();
        parent::tearDown();
    }

    public function test_ufw_is_the_default(): void
    {
        config(['env.FIREWALL_PROVIDER' => null]);

        $this->assertInstanceOf(UfwFirewall::class, FirewallFactory::default());
        $this->assertSame(['ufw'], FirewallFactory::names());
    }

    public function test_another_provider_is_a_registration_and_a_setting(): void
    {
        $fake = $this->fake([]);
        FirewallFactory::register('Firewalld', fn (): Firewall => $fake);
        config(['env.FIREWALL_PROVIDER' => 'firewalld']);

        $this->assertSame($fake, FirewallFactory::default());
        $this->assertEqualsCanonicalizing(['firewalld', 'ufw'], FirewallFactory::names());
    }

    public function test_an_unknown_provider_names_the_ones_there_are(): void
    {
        config(['env.FIREWALL_PROVIDER' => 'pf']);

        $this->expectException(FirewallException::class);
        $this->expectExceptionMessage('available: ufw');

        FirewallFactory::default();
    }

    public function test_the_api_does_not_touch_an_engine_rule_or_one_it_cannot_represent(): void
    {
        $managed = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '2011', 'comment' => 'panelalpha: engine api']);
        $hostOnly = new FirewallRule('limit', 'in', 'tcp', '2222', editable: false, raw: 'limit tcp 2222 any any any in');
        $fake = $this->fake([$managed, $hostOnly]);
        FirewallFactory::register('fake', fn (): Firewall => $fake);
        config(['env.FIREWALL_PROVIDER' => 'fake']);

        foreach ([$managed, $hostOnly] as $rule) {
            try {
                (new FirewallController())->deleteRule($rule->id());
                $this->fail('deleted ' . $rule->id());
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('id', $e->errors());
            }
        }
        $this->assertSame([], $fake->deleted);
    }

    /** @param list<FirewallRule> $rules */
    private function fake(array $rules): Firewall
    {
        return new class ($rules) implements Firewall {
            /** @var list<string> */
            public array $deleted = [];

            /** @param list<FirewallRule> $rules */
            public function __construct(private array $rules)
            {
            }

            public function name(): string
            {
                return 'fake';
            }

            public function status(): FirewallStatus
            {
                return new FirewallStatus('fake', true);
            }

            public function rules(): array
            {
                return $this->rules;
            }

            public function rule(string $id): FirewallRule
            {
                foreach ($this->rules as $rule) {
                    if ($rule->id() === $id) {
                        return $rule;
                    }
                }
                throw FirewallNotFound::rule($id);
            }

            public function addRule(FirewallRule $rule): FirewallRule
            {
                return $rule;
            }

            public function updateRule(string $id, FirewallRule $rule): FirewallRule
            {
                return $rule;
            }

            public function deleteRule(string $id): FirewallRule
            {
                $this->deleted[] = $id;

                return $this->rule($id);
            }

            public function enable(): void
            {
            }

            public function disable(): void
            {
            }

            public function reload(): void
            {
            }

            public function logs(int $limit = 100, ?string $type = null, ?string $address = null): array
            {
                return [];
            }

            public function trustedAddresses(): array
            {
                return [];
            }

            public function trust(TrustedAddress $address): TrustedAddress
            {
                return $address;
            }

            public function untrust(string $id): TrustedAddress
            {
                throw FirewallNotFound::trustedAddress($id);
            }
        };
    }
}
