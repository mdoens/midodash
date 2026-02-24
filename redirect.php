<?php

// OAuth2 callback — wisselt authorization code om voor tokens
// Credentials via environment variables (Coolify)
$saxo = [
    'app_key'        => getenv('SAXO_APP_KEY'),
    'app_secret'     => getenv('SAXO_APP_SECRET'),
    'redirect_uri'   => getenv('SAXO_REDIRECT_URI') ?: 'https://mido.barcelona2.doens.nl',
    'token_endpoint' => 'https://live.logonvalidation.net/token',
];

if (!isset($_GET['code'])) {
    die('Geen authorization code ontvangen. Error: ' . ($_GET['error'] ?? 'onbekend'));
}

$code = $_GET['code'];

// Wissel code om voor access + refresh token
$ch = curl_init($saxo['token_endpoint']);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type'    => 'authorization_code',
        'client_id'     => $saxo['app_key'],
        'client_secret' => $saxo['app_secret'],
        'code'          => $code,
        'redirect_uri'  => $saxo['redirect_uri'],
    ]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_RETURNTRANSFER => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$tokens = json_decode($response, true);

if ($httpCode >= 300 || !isset($tokens['access_token'])) {
    die("Token exchange mislukt (HTTP {$httpCode}): " . $response);
}

// Toon tokens op scherm (kopieer access_token naar config.php)
header('Content-Type: text/html; charset=utf-8');
echo "<h2>Saxo OAuth2 succesvol!</h2>";
echo "<p><strong>Access Token</strong> (verloopt over {$tokens['expires_in']}s):</p>";
echo "<textarea rows='6' cols='80' onclick='this.select()'>{$tokens['access_token']}</textarea>";
if (isset($tokens['refresh_token'])) {
    echo "<p><strong>Refresh Token:</strong></p>";
    echo "<textarea rows='3' cols='80' onclick='this.select()'>{$tokens['refresh_token']}</textarea>";
}
