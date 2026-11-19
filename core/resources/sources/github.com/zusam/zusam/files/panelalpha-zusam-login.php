<?php
// An installation made before the engine owned the login has upstream's default first
// user, zusam / zusam. While that login still works, it is changed to the one the
// engine generated (INIT_USER / INIT_PASSWORD), through Zusam's own API: POST
// /api/login, GET /api/me, PUT /api/users/{id}. A new installation was created with
// the engine's login already, and a password changed in Zusam no longer matches the
// default, so neither is touched. Run by the `ready` service once the API answers;
// always exits 0.
$say = fn (string $m) => print("[panelalpha] zusam: {$m}\n");
$user = getenv('INIT_USER') ?: '';
$password = getenv('INIT_PASSWORD') ?: '';
if ($user === '' || $password === '' || ($user === 'zusam' && $password === 'zusam')) {
    exit(0);
}

function call(string $method, string $path, ?array $body = null, ?string $key = null): array
{
    $headers = ['Content-Type: application/json'];
    if ($key !== null) {
        $headers[] = 'X-AUTH-TOKEN: '.$key;
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body === null ? '' : json_encode($body),
        'ignore_errors' => true,
        // The first requests after a start can wait on Symfony's cache warm-up.
        'timeout' => 120,
    ]]);
    $answer = @file_get_contents('http://app:8080/api'.$path, false, $context);
    preg_match('#^HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);

    return [(int) ($m[1] ?? 0), json_decode((string) $answer, true)];
}

[$status, $login] = call('POST', '/login', ['login' => 'zusam', 'password' => 'zusam']);
if ($status !== 200 || empty($login['api_key'])) {
    exit(0); // no default login left
}
[$status, $me] = call('GET', '/me', null, $login['api_key']);
if ($status !== 200 || empty($me['id'])) {
    $say('the default login zusam / zusam still works, but its user could not be read');
    exit(0);
}
[$status] = call('PUT', '/users/'.$me['id'], ['login' => $user, 'password' => $password], $login['api_key']);
if ($status === 409) {
    // INIT_USER is taken by another user: keep the login, change the password.
    [$status] = call('PUT', '/users/'.$me['id'], ['password' => $password], $login['api_key']);
    $user = 'zusam';
}
$say($status === 200
    ? "the default login zusam / zusam is now {$user} with the password the engine generated"
    : "could not change the default login zusam / zusam (HTTP {$status})");
exit(0);
