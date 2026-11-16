<?php

declare(strict_types=1);

ini_set('session.use_cookies', 'true');
session_set_cookie_params(0, '/phpmyadmin/', '', false, true);
session_name('PMASignonSession');
@session_start();

$token = (!empty($_REQUEST['pmassotoken']) && is_string($_REQUEST['pmassotoken'])) ? $_REQUEST['pmassotoken'] : null;

if ($token) {

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($curl, CURLOPT_URL, 'http://core.shared-hosting.palocal/api/mysql/phpmyadmin-sso-token');
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(['token' => $token]));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    curl_close($curl);

    $result = json_decode($response);

    if (
        !empty($error)
        || empty($result)
        || empty($result->data)
        || empty($result->data->username)
        || empty($result->data->password)
    ) {
        session_destroy();
        @ob_clean();
        header('Location: ..');
        die();
    }

    session_regenerate_id(true);
    $_SESSION['PMA_single_signon_user'] = $result->data->username;
    $_SESSION['PMA_single_signon_password'] = $result->data->password;
    $_SESSION['PMA_single_signon_HMAC_secret'] = hash('sha1', uniqid(strval(rand()), true));

    @session_write_close();
    @ob_clean();
    header('Location: ./index.php');
    die();
}

session_destroy();
@ob_clean();
header('Location: ..');
die();
