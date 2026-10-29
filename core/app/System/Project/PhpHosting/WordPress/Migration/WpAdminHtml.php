<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

/**
 * Parses wp-admin HTML the same way the panel Wordpress ResponseHelper does.
 */
final class WpAdminHtml
{
    public static function ajaxNonce(string $responseHtml): string
    {
        if (preg_match('/"_ajax_nonce":"([a-z0-9]+)"/i', $responseHtml, $output) === 1) {
            return $output[1];
        }

        if (preg_match('/"ajax_nonce":"(.*)"/', $responseHtml, $output) === 1) {
            return $output[1];
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    public static function inputs(string $responseHtml): array
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML($responseHtml);

        $inputs = $doc->getElementsByTagName('input');
        $inputValues = [];

        /** @var \DOMElement $input */
        foreach ($inputs as $input) {
            $name = $input->getAttribute('name');
            $value = $input->getAttribute('value');
            if ($name !== '') {
                $inputValues[$name] = $value;
            }
        }

        return $inputValues;
    }

    /**
     * @return array{active?: array<string, string>, inactive?: array<string, string>}
     */
    public static function plugins(string $response): array
    {
        $plugins = [];
        preg_match_all('/"(plugins\.php\?action=activate(.*))"/U', html_entity_decode($response), $matches);
        foreach ($matches[1] as $match) {
            preg_match('/plugin=(.*)\&/U', $match, $nameMatches);
            $pluginName = explode('/', urldecode($nameMatches[1] ?? ''))[0];
            if ($pluginName !== '') {
                $plugins['inactive'][$pluginName] = urldecode($match);
            }
        }

        preg_match_all('/"(plugins\.php\?action=deactivate(.*))"/U', html_entity_decode($response), $matches);
        foreach ($matches[1] as $match) {
            preg_match('/plugin=(.*)\&/U', $match, $nameMatches);
            $pluginName = explode('/', urldecode($nameMatches[1] ?? ''))[0];
            if ($pluginName !== '') {
                $plugins['active'][$pluginName] = urldecode($match);
            }
        }

        return $plugins;
    }

    public static function wpHomeUrl(string $response): ?string
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML($response);
        $el = $doc->getElementById('backtoblog');
        if ($el === null) {
            return null;
        }
        $links = $el->getElementsByTagName('a');
        if ($links->length === 0) {
            return null;
        }
        $href = $links->item(0)?->getAttribute('href');
        if (! is_string($href) || $href === '') {
            return null;
        }

        return rtrim($href, '/');
    }

    /**
     * Where the login form posts to: the real login URL, also behind a custom login path.
     */
    public static function loginFormAction(string $response): ?string
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML($response);
        $form = $doc->getElementById('loginform');
        if ($form === null) {
            return null;
        }

        $action = trim(html_entity_decode($form->getAttribute('action')));

        return filter_var($action, FILTER_VALIDATE_URL) === false ? null : $action;
    }

    /**
     * WordPress version from the `generator` meta tag, when the site still prints it.
     */
    public static function generatorVersion(string $response): ?string
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML($response);

        /** @var \DOMElement $meta */
        foreach ($doc->getElementsByTagName('meta') as $meta) {
            if (strtolower($meta->getAttribute('name')) !== 'generator') {
                continue;
            }
            if (preg_match('/^WordPress\s+(\d+(?:\.\d+)*)/i', trim($meta->getAttribute('content')), $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    public static function isCloudflareBlocked(string $responseHtml): bool
    {
        if ($responseHtml === '') {
            return false;
        }

        $dom = new \DOMDocument;
        @$dom->loadHTML($responseHtml);
        $xpath = new \DOMXPath($dom);

        return $xpath->query('//*[@id="wsidchk-form"]')->length > 0
            && $xpath->query('//*[@id="wsidchk"]')->length > 0;
    }

    public static function isEmailVerificationAlert(string $responseHtml): bool
    {
        if ($responseHtml === '') {
            return false;
        }

        $doc = new \DOMDocument;
        @$doc->loadHTML($responseHtml);
        $forms = $doc->getElementsByTagName('form');

        /** @var \DOMElement $form */
        foreach ($forms as $form) {
            if (str_contains($form->getAttribute('action'), '?action=confirm_admin_email')) {
                return true;
            }
        }

        return false;
    }
}
