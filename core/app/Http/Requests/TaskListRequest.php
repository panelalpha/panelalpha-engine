<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TaskListRequest extends FormRequest
{
    public const SCOPE_MINE = 'mine';

    public const SCOPE_ALL = 'all';

    public const DEFAULT_PER_PAGE = 50;

    /** @var array<string, list<string>> */
    private const STATUS_ALIASES = [
        'active' => [Task::STATUS_QUEUED, Task::STATUS_RUNNING],
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['sometimes', 'string'],
            'status' => ['sometimes', 'string'],
            'action' => ['sometimes', 'string'],
            'since' => ['sometimes', 'date'],
            'scope' => ['sometimes', Rule::in([self::SCOPE_MINE, self::SCOPE_ALL])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->requestedStatuses() as $status) {
                if ($this->isKnownStatus($status)) {
                    continue;
                }
                $validator->errors()->add('status', "Unknown status '{$status}'.");
            }
        });
    }

    public function scope(): string
    {
        $scope = $this->query('scope');

        return is_string($scope) ? $scope : self::SCOPE_MINE;
    }

    public function perPage(): int
    {
        $perPage = $this->query('per_page');

        return is_numeric($perPage) ? (int) $perPage : self::DEFAULT_PER_PAGE;
    }

    public function sinceAt(): ?Carbon
    {
        $since = $this->query('since');

        return is_string($since) && $since !== '' ? Carbon::parse($since) : null;
    }

    /** The statuses to keep, with `active` split into the two it names. */
    public function statuses(): array
    {
        $statuses = [];
        foreach ($this->requestedStatuses() as $status) {
            foreach (self::STATUS_ALIASES[$status] ?? [$status] as $expanded) {
                $statuses[] = $expanded;
            }
        }

        return array_values(array_unique($statuses));
    }

    /** @return list<string> */
    private function requestedStatuses(): array
    {
        $raw = $this->query('status');
        if (! is_string($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $status): bool => $status !== '',
        ));
    }

    private function isKnownStatus(string $status): bool
    {
        return isset(self::STATUS_ALIASES[$status]) || in_array($status, Task::STATUSES, true);
    }
}
