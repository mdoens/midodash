<?php

// OAuth2 callback — wisselt authorization code om voor tokens
$config = require __DIR__ . '/config.php';
$saxo = $config['saxo'];

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

if ($httpCode !== 200 || !isset($tokens['access_token'])) {
    die("Token exchange mislukt (HTTP {$httpCode}): " . $response);
}

// Sla tokens op
$tokenFile = __DIR__ . '/saxo_tokens.json';
$tokens['created_at'] = time();
file_put_contents($tokenFile, json_encode($tokens, JSON_PRETTY_PRINT));

echo "<h2>Saxo OAuth2 succesvol!</h2>";
echo "<p>Access token opgeslagen in <code>saxo_tokens.json</code></p>";
echo "<p>Token verloopt over {$tokens['expires_in']} seconden.</p>";
echo "<p>Je kunt nu <code>php saxo_fetch.php</code> draaien.</p>";
