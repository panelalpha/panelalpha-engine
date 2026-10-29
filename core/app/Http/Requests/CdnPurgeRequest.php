<?php

namespace App\Http\Requests;

use App\Lib\Domains\ProjectHostnames;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CdnPurgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'everything' => ['sometimes', 'boolean'],
            'files' => ['sometimes', 'array', 'max:30'],
            'files.*' => ['string', 'url', 'max:2048'],
        ];
    }

    public function purgesEverything(): bool
    {
        return (bool) ($this->validated()['everything'] ?? false);
    }

    /**
     * @return list<string>
     */
    public function purgeFiles(): array
    {
        return self::files($this->validated()['files'] ?? null);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('everything') && self::files($this->input('files')) === []) {
                $validator->errors()->add('files', 'Send everything=true, or a non-empty list of files.');
            }

            $this->refuseOtherHostnames($validator);
        });
    }

    /**
     * A URL purges whatever the zone caches for it, so each must be on one of this project's
     * own hostnames; the project itself is left to the controller's 404.
     */
    private function refuseOtherHostnames(Validator $validator): void
    {
        $project = User::findByUsername((string) $this->route('username'));
        $files = $this->input('files');
        if ($project === null || ! is_array($files)) {
            return;
        }

        $own = ProjectHostnames::of($project);
        foreach ($files as $index => $url) {
            if (! is_string($url) || $validator->errors()->has("files.{$index}") || self::isOn($url, $own)) {
                continue;
            }

            $validator->errors()->add("files.{$index}", "{$url} is not on one of this project's hostnames.");
        }
    }

    /**
     * @param  list<string>  $hostnames
     */
    private static function isOn(string $url, array $hostnames): bool
    {
        return in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), $hostnames, true);
    }

    /**
     * @return list<string>
     */
    private static function files(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $file): bool => is_string($file) && $file !== ''));
    }
}
