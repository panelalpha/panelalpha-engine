<?php

namespace App\System\Services;

use App\Models\Setting;
use App\System as EngineSystem;
use Illuminate\Support\Facades\Blade;

class Exim
{
    public function __construct(
        private EngineSystem $system,
    ) {
    }

    public function rebuildEximConfig(): void
    {
        $config = $this->eximConfig();

        // update-exim4.conf.conf
        $updateConfPath = $this->system->engineDirPath() . '/config/exim/update-exim4.conf.conf';
        $updateConfTemplatePath = $this->system->engineDirPath() . '/templates/config/exim-update-conf.blade.php';
        $updateConfTemplate = $this->system->filesystem()->fileGetContents($updateConfTemplatePath);

        $type = 'internet';
        $smarthost = '';
        switch ($config['smarthost_provider']) {
            case 'sendgrid':
                $type = 'smarthost';
                $smarthost = 'smtp.sendgrid.net::587';
                break;
            case 'mailchannels':
                $type = 'smarthost';
                $smarthost = 'smtp.mailchannels.net::25';
                break;
            case 'amazon_ses':
                $type = 'smarthost';
                $smarthost = $config['amazon_ses_smtp_endpoint'] . '::' . $config['amazon_ses_starttls_port'];
                break;
            case 'smtp':
                $type = 'smarthost';
                $smarthost = $config['smtp_host'] . '::' . $config['smtp_port'];
                break;
        }

        $templateVars = [
            'dc_readhost' => $config['sender_domain'],
            'dc_eximconfig_configtype' => $type,
            'dc_smarthost' => $smarthost,
        ];
        $updateConf = Blade::render($updateConfTemplate, $templateVars);
        $this->system->filesystem()->filePutContents($updateConfPath, $updateConf);

        // passwd.client
        $passwdPath = $this->system->engineDirPath() . '/config/exim/passwd.client';
        $passwdTemplatePath = $this->system->engineDirPath() . '/templates/config/exim-passwd.blade.php';
        $passwdTemplate = $this->system->filesystem()->fileGetContents($passwdTemplatePath);

        $username = '';
        $password = '';
        switch ($config['smarthost_provider']) {
            case 'sendgrid':
                $username = 'apikey';
                $password = $config['sendgrid_api_token'];
                break;
            case 'mailchannels':
                $username = $config['mailchannels_username'];
                $password = $config['mailchannels_password'];
                break;
            case 'amazon_ses':
                $username = $config['amazon_ses_smtp_username'];
                $password = $config['amazon_ses_smtp_password'];
                break;
            case 'smtp':
                $username = $config['smtp_username'];
                $password = $config['smtp_password'];
                break;
        }

        $templateVars = [
            'username' => $username,
            'password' => $password,
        ];
        $passwd = Blade::render($passwdTemplate, $templateVars);
        $this->system->filesystem()->filePutContents($passwdPath, $passwd);

        // rewrite.conf
        $rewritePath = $this->system->engineDirPath() . '/config/exim/rewrite.conf';
        $rewriteTemplatePath = $this->system->engineDirPath() . '/templates/config/exim-rewrite.blade.php';
        $rewriteTemplate = $this->system->filesystem()->fileGetContents($rewriteTemplatePath);

        $templateVars = [
            'sender_domain' => $config['sender_domain'],
        ];
        $rewrite = Blade::render($rewriteTemplate, $templateVars);
        $this->system->filesystem()->filePutContents($rewritePath, $rewrite);

        $transportPath = $this->system->engineDirPath() . '/config/exim/transport.conf';
        $transportTemplatePath = $this->system->engineDirPath() . '/templates/config/exim-transport.blade.php';
        $transportTemplate = $this->system->filesystem()->fileGetContents($transportTemplatePath);

        $templateVars = [
            'smtp_implicit_tls' => !empty($config['smtp_implicit_tls']),
        ];
        $transport = Blade::render($transportTemplate, $templateVars);
        $this->system->filesystem()->filePutContents($transportPath, $transport);

        $this->system->exec([
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->system->composeFilePath(),
            'exec',
            'mail',
            'update-exim4.conf',
        ]);

        $this->system->exec([
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->system->composeFilePath(),
            'exec',
            'mail',
            'service',
            'exim4',
            'reload',
        ]);
    }

    public function sendTestEmail(string $email): array
    {
        // Interpolated into the To: header below.
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid test email address.');
        }

        $message = ""
            . "From: Tester <wordpress@userdomain.com>\n"
            . "To: {$email}\n"
            . "Subject: Test email from PanelAlpha Engine\n\n"
            . "This is a test email sent from PanelAlpha Engine.\n";

        $to = escapeshellarg($email);
        $process = $this->system->runProcess([
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->system->composeFilePath(),
            'exec',
            'mail',
            'bash',
            '-c',
            "echo " . escapeshellarg($message) . " | exim4 -v -odf $to"
        ]);

        return [
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exit_code' => $process->getExitCode(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eximConfig(): array
    {
        return Setting::getEximConfig();
    }
}
