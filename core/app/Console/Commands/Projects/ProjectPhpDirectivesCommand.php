<?php

namespace App\Console\Commands\Projects;

use App\Console\Commands\Concerns\PrintsPhpSettings;
use App\Http\Requests\UserPhpListCustomIniSettingsRequest;
use App\Lib\Project\CustomIniSettings;
use App\Models\User;
use App\System;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class ProjectPhpDirectivesCommand extends Command
{
    use PrintsPhpSettings;

    protected $signature = 'project:php-directives
        {username : Hosting account username}
        {version : PHP version}';

    protected $description = 'Show account PHP directives for one PHP version';

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));
        $version = trim((string) $this->argument('version'));
        if ($username === '' || $version === '') {
            $this->error('Username and PHP version are required.');

            return 1;
        }

        try {
            Validator::make(['php_version' => $version], (new UserPhpListCustomIniSettingsRequest())->rules())->validate();

            $user = User::findByUsername($username) ?? throw new NotFoundHttpException('Not found');
            $settings = (new CustomIniSettings(app(System::class)))->get($user, $version);
            $this->requireEncodable($settings);
        } catch (Throwable $e) {
            return $this->rejectWithException($e);
        }

        $this->printDirectiveMap($settings);

        return 0;
    }
}
