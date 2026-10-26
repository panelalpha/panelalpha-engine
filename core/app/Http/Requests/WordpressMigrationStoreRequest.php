<?php

namespace App\Http\Requests;

use App\Models\WordpressMigration;
use App\Rules\DomainName;
use App\Rules\ProjectName;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTargetResolver;
use App\System\Project\PhpHosting\WordPress\Migration\SourceFetchGuard;
use App\System\Project\PhpHosting\WordPress\Migration\SourceIpPolicy;
use App\System\Project\PhpHosting\WordPress\Migration\TargetChoice;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Start-migration body, also the body of the connection test. The source URL
 * and `source_ip` are rejected here, before any secret is stored.
 *
 * @method array{url: string, method?: string, login_url?: ?string, username?: ?string, password?: ?string, source_token?: ?string, source_ip?: ?string, domain?: ?string, project?: ?string, project_name?: ?string} validated($key = null, $default = null)
 */
class WordpressMigrationStoreRequest extends FormRequest
{
    /**
     * Optional container binding: callable(string $host): list<string>.
     * Tests use it so a start does not depend on live DNS.
     */
    public const SOURCE_RESOLVER = 'wordpress-migration.source-resolver';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::rulesFor($this->all());
    }

    /**
     * Also used by wordpress:migrate, which validates its options the same way.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function rulesFor(array $input): array
    {
        $manual = ($input['method'] ?? null) === WordpressMigration::METHOD_MANUAL_PLUGIN;
        $pinned = is_string($input['source_ip'] ?? null) && $input['source_ip'] !== '';

        return [
            'url' => ['required', 'string', self::sourceUrlRule($pinned)],
            'method' => ['sometimes', 'string', Rule::in(WordpressMigration::METHODS)],
            'login_url' => ['nullable', 'string', 'url', self::sourceUrlRule($pinned)],
            'username' => [Rule::requiredIf(! $manual), 'nullable', 'string'],
            'password' => [Rule::requiredIf(! $manual), 'nullable', 'string'],
            'source_token' => [Rule::requiredIf($manual), 'nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'source_ip' => ['nullable', 'string', 'ip', self::sourceIpRule()],
            ...self::targetRules(),
        ];
    }

    /**
     * Where the import lands; which of them may be combined is
     * {@see MigrationTargetResolver}'s, since it answers with a problem code.
     *
     * @return array<string, mixed>
     */
    private static function targetRules(): array
    {
        return [
            'domain' => ['nullable', 'string', 'regex:'.DomainName::REGEX],
            'project' => ['nullable', 'string'],
            'project_name' => ['nullable', 'string', new ProjectName('project_name')],
        ];
    }

    /** The target these fields name, or the problem that refuses it. */
    public function target(): MigrationTarget
    {
        return (new MigrationTargetResolver)->resolveChoice((string) $this->validated('url'), $this->targetChoice());
    }

    public function targetChoice(): TargetChoice
    {
        return TargetChoice::fromValidated($this->validated());
    }

    /**
     * With `source_ip` the host's own DNS is never used, so only the URL's shape is checked.
     */
    private static function sourceUrlRule(bool $pinned): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($pinned): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            try {
                $pinned
                    ? SourceFetchGuard::assertShape($value)
                    : SourceFetchGuard::assertAllowed($value, self::hostResolver());
            } catch (RuntimeException $e) {
                $fail($e->getMessage());
            }
        };
    }

    private static function sourceIpRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || filter_var($value, FILTER_VALIDATE_IP) === false) {
                return;
            }

            $refusal = app(SourceIpPolicy::class)->refusal($value);
            if ($refusal !== null) {
                $fail($refusal);
            }
        };
    }

    /**
     * Also read by wordpress:migrate, which checks the source the same way.
     *
     * @return (callable(string): list<string>)|null
     */
    public static function hostResolver(): ?callable
    {
        if (! app()->bound(self::SOURCE_RESOLVER)) {
            return null;
        }

        $resolver = app(self::SOURCE_RESOLVER);
        if (! is_callable($resolver)) {
            return null;
        }

        return $resolver;
    }
}
