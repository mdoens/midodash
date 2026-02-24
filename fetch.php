<?php

$config = require __DIR__ . '/config.php';

$token   = $config['token'];
$queryId = $config['query_id'];

$baseUrl = 'https://gdcdyn.interactivebrokers.com/Universal/servlet/FlexStatementService';

// Stap 1: Request sturen → reference code ophalen
$requestUrl = sprintf('%s.SendRequest?t=%s&q=%s&v=3', $baseUrl, $token, $queryId);

echo "Flex query request versturen...\n";

$response = file_get_contents($requestUrl);
if ($response === false) {
    die("Fout: kon geen verbinding maken met de IB Flex API.\n");
}

$xml = simplexml_load_string($response);
if ($xml === false) {
    die("Fout: ongeldig XML-antwoord van SendRequest.\n");
}

if ((string)$xml->Status !== 'Success') {
    die("Fout van IB: " . (string)$xml->ErrorMessage . "\n");
}

$referenceCode = (string)$xml->ReferenceCode;
echo "Reference code ontvangen: {$referenceCode}\n";

// Stap 2: Wachten en resultaat ophalen
$statementUrl = sprintf('%s.GetStatement?q=%s&t=%s&v=3', $baseUrl, $referenceCode, $token);

$maxRetries = 10;
$waitSeconds = 5;

for ($i = 1; $i <= $maxRetries; $i++) {
    echo "Poging {$i}/{$maxRetries} — wachten {$waitSeconds}s...\n";
    sleep($waitSeconds);

    $result = file_get_contents($statementUrl);
    if ($result === false) {
        echo "Geen antwoord, opnieuw proberen...\n";
        continue;
    }

    // IB geeft XML terug met een warning als het nog niet klaar is
    $check = simplexml_load_string($result);
    if ($check !== false && isset($check->Status) && (string)$check->Status !== 'Success') {
        echo "Nog niet klaar: " . (string)$check->ErrorMessage . "\n";
        continue;
    }

    // Resultaat opslaan
    $outputFile = __DIR__ . '/statement_' . date('Y-m-d_His') . '.xml';
    file_put_contents($outputFile, $result);
    echo "Statement opgeslagen: {$outputFile}\n";
    exit(0);
}

die("Fout: na {$maxRetries} pogingen nog geen resultaat ontvangen.\n");
