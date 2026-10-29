<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesBackupContainerCredentials;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BackupContainerStoreRequest extends FormRequest
{
    use NormalizesBackupContainerCredentials;

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
            'name' => ['required', 'string', Rule::unique('backup_containers', 'name')],
            'driver' => ['required', Rule::in(['local', 's3', 'ftp', 'ftps', 'sftp'])],
            'location' => ['required', 'string', 'max:1024'],
            'credentials' => ['nullable', 'array'],
        ];
    }

    /**
     * @return ?array<string, mixed>
     */
    public function credentials(): ?array
    {
        /** @var string $driver */
        $driver = $this->validated('driver');
        /** @var string $location */
        $location = $this->validated('location');
        if ($driver === 'local') {
            $this->assertLocalLocationIsSafe($location);
        }

        /** @var ?array<string, mixed> $credentials */
        $credentials = $this->validated('credentials');

        return $this->normalizeCredentials($driver, $credentials);
    }
}
