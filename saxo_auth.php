<?php

// Genereer de OAuth2 authorize URL voor Saxo Live
$config = require __DIR__ . '/config.php';
$saxo = $config['saxo'];

$state = bin2hex(random_bytes(16));

$url = $saxo['auth_endpoint'] . '?' . http_build_query([
    'client_id'     => $saxo['app_key'],
    'response_type' => 'code',
    'redirect_uri'  => $saxo['redirect_uri'],
    'state'         => $state,
]);

echo "Open deze URL in je browser om in te loggen bij Saxo:\n\n";
echo $url . "\n";
