<?php

$config = require __DIR__ . '/config.php';
$saxo = $config['saxo'];

// Gebruik token uit saxo_tokens.json (OAuth) als beschikbaar, anders uit config
$tokenFile = __DIR__ . '/saxo_tokens.json';
if (file_exists($tokenFile)) {
    $tokens = json_decode(file_get_contents($tokenFile), true);
    $accessToken = $tokens['access_token'];
} else {
    $accessToken = $saxo['access_token'];
}

$url = $saxo['api_base'] . '/port/v1/positions/me?FieldGroups=DisplayAndFormat,PositionBase,PositionView';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING       => 'gzip',
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 401) {
    die("Token verlopen. Haal een nieuw 24-uurs token op via het Saxo developer portal,\nof gebruik: php saxo_auth.php\n");
}

if ($httpCode !== 200) {
    die("Fout {$httpCode}: {$response}\n");
}

$data = json_decode($response, true);

echo "=== SAXO POSITIES ===\n";

if (empty($data['Data'])) {
    echo "Geen openstaande posities gevonden.\n";
    echo "(Dit is een SIM-omgeving — mogelijk moet je eerst simulatie-posities openen.)\n";
    exit(0);
}

echo sprintf("%-12s %-30s %-6s %10s %12s %12s %12s\n",
    'Symbol', 'Omschrijving', 'Type', 'Aantal', 'Open prijs', 'Huidige', 'P/L');
echo str_repeat('-', 98) . "\n";

$totalPl = 0;

foreach ($data['Data'] as $pos) {
    $display = $pos['DisplayAndFormat'] ?? [];
    $base    = $pos['PositionBase'] ?? [];
    $view    = $pos['PositionView'] ?? [];

    $symbol  = $display['Symbol'] ?? '?';
    $desc    = $display['Description'] ?? '';
    $type    = $base['AssetType'] ?? '';
    $amount  = $base['Amount'] ?? 0;
    $openPx  = $base['OpenPrice'] ?? 0;
    $curPx   = $view['CurrentPrice'] ?? 0;
    $pl      = $view['ProfitLossOnTrade'] ?? 0;

    $totalPl += $pl;

    echo sprintf("%-12s %-30s %-6s %10s %12s %12s %12s\n",
        $symbol,
        mb_substr($desc, 0, 30),
        $type,
        number_format($amount, 0, ',', '.'),
        number_format($openPx, 2, ',', '.'),
        number_format($curPx, 2, ',', '.'),
        number_format($pl, 2, ',', '.')
    );
}

echo str_repeat('-', 98) . "\n";
echo sprintf("%-49s %36s %12s\n", 'TOTAAL P/L', '', number_format($totalPl, 2, ',', '.'));
