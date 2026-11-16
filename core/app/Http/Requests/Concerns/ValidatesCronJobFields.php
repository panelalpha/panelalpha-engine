<?php

namespace App\Http\Requests\Concerns;

use App\Lib\Helpers\CronSchedule;
use App\Models\User;
use App\System\Project\CronRunner;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountCron;
use App\System\Project\Dind\ContainerOperations;
use Closure;

/**
 * A container project's job names the service it runs in; a PHP hosting
 * project has none to name.
 */
trait ValidatesCronJobFields
{
    /**
     * TrimStrings does this for REST; an MCP tool dispatches to the router
     * without it, so the ends are trimmed here to make both callers equal.
     */
    protected function prepareForValidation(): void
    {
        $trimmed = [];
        foreach (['command', 'service', 'minute', 'hour', 'day_of_month', 'month', 'day_of_week'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $trimmed[$field] = trim($value);
            }
        }
        $this->merge($trimmed);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules()
    {
        // A crontab entry is one line: a line break would split it in two.
        $oneLine = 'not_regex:/[\r\n\x00]/';
        $inService = $this->runsInAService();

        $rules = [
            'command' => [
                'string',
                'required',
                $oneLine,
                ...($inService
                    ? [$this->fitsTheRunner(...)]
                    : [$this->hasNoEscapedPercent(...), $this->fitsTheAccountRunner(...)]),
            ],
            'service' => $inService
                ? ['bail', 'required', 'string', 'max:63', 'regex:'.ContainerOperations::SERVICE_NAME]
                : ['prohibited'],
        ];
        foreach (CronSchedule::fieldNames() as $field) {
            $rules[$field] = ['bail', 'string', 'required', $oneLine, $this->cronField(...)];
        }

        return $rules;
    }

    private function runsInAService(): bool
    {
        $username = $this->route('username');
        $user = is_string($username) ? User::findByUsername($username) : null;

        return $user !== null && $user->project()->runtime() instanceof Dind;
    }

    private function fitsTheRunner(string $attribute, mixed $value, Closure $fail): void
    {
        if (strlen((string) $value) > AccountCron::MAX_COMMAND_BYTES) {
            $fail('The :attribute field must be at most '.AccountCron::MAX_COMMAND_BYTES.' bytes for a job that runs in a service.');
        }
    }

    /** A classic account's job carries the runner and the command in base64 in front of it, and cron reads 1000 characters. */
    private function fitsTheAccountRunner(string $attribute, mixed $value, Closure $fail): void
    {
        if (! CronRunner::fits((string) $value)) {
            $fail('The :attribute field must be at most '.CronRunner::MAX_COMMAND_BYTES.' bytes for a job on PHP hosting.');
        }
    }

    /**
     * Every `%` is escaped for cron as the job is written, and cron has no way
     * to run a backslash followed by one, so a command cannot ask for that.
     */
    private function hasNoEscapedPercent(string $attribute, mixed $value, Closure $fail): void
    {
        if (str_contains((string) $value, '\\%')) {
            $fail('The :attribute field must not contain \\%: write % as it is, and it is escaped for cron.');
        }
    }

    /**
     * Each schedule problem under its own field, so errors.hour says what
     * crond would refuse in the hour.
     */
    private function cronField(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (CronSchedule::fieldErrors($attribute, (string) $value) as $error) {
            $fail($error);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages()
    {
        return [
            'not_regex' => 'The :attribute field must be a single line: no line breaks or NUL bytes.',
            'service.required' => 'The service field is required: a container project runs each cron job in one of its services.',
            'service.prohibited' => 'The service field is only for container projects: a PHP hosting project runs its cron jobs in its own container.',
            'service.regex' => 'The service field must be a Compose service name: letters, digits, _ and -.',
        ];
    }
}
