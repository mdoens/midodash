<?php

$file = $argv[1] ?? glob(__DIR__ . '/statement_*.xml')[0] ?? null;

if (!$file || !file_exists($file)) {
    die("Gebruik: php parse.php [statement.xml]\n");
}

$xml = simplexml_load_file($file);
$statement = $xml->FlexStatements->FlexStatement;

// Account info
$account = $statement->AccountInformation;
echo "Account: {$account['accountId']} ({$account['currency']})\n";
echo "Periode: {$statement['fromDate']} t/m {$statement['toDate']}\n\n";

// Cash report samenvatting
$cash = $statement->CashReport->CashReportCurrency[0];
echo "=== CASH OVERZICHT ===\n";
echo sprintf("Stortingen:      %12s\n", number_format((float)$cash['deposits'], 2, ',', '.'));
echo sprintf("Commissies:      %12s\n", number_format((float)$cash['commissions'], 2, ',', '.'));
echo sprintf("Eindsaldo:       %12s\n", number_format((float)$cash['endingCash'], 2, ',', '.'));
echo "\n";

// Open posities
echo "=== OPEN POSITIES ===\n";
echo sprintf("%-10s %-6s %10s %14s %14s %10s\n", 'Symbol', 'Type', 'Aantal', 'Waarde', 'Kostprijs', 'P/L %');
echo str_repeat('-', 70) . "\n";

$totalValue = 0;
$totalCost = 0;

foreach ($statement->OpenPositions->OpenPosition as $pos) {
    $symbol   = (string)$pos['symbol'];
    $category = (string)$pos['assetCategory'];
    $qty      = (float)$pos['position'];
    $value    = (float)$pos['positionValue'];
    $costPrice = (float)$pos['costBasisPrice'];
    $cost     = $costPrice * $qty;
    $plPct    = $cost > 0 ? (($value - $cost) / $cost) * 100 : 0;

    $totalValue += $value;
    $totalCost  += $cost;

    echo sprintf(
        "%-10s %-6s %10s %14s %14s %9s%%\n",
        $symbol,
        $category,
        number_format($qty, 0, ',', '.'),
        number_format($value, 2, ',', '.'),
        number_format($cost, 2, ',', '.'),
        number_format($plPct, 1, ',', '.')
    );
}

$totalPl = $totalCost > 0 ? (($totalValue - $totalCost) / $totalCost) * 100 : 0;
echo str_repeat('-', 70) . "\n";
echo sprintf("%-17s %10s %14s %14s %9s%%\n",
    'TOTAAL', '',
    number_format($totalValue, 2, ',', '.'),
    number_format($totalCost, 2, ',', '.'),
    number_format($totalPl, 1, ',', '.')
);
echo "\n";

// Trades samenvatting
$trades = $statement->Trades;
$tradeCount = count($trades->Trade ?? []);
echo "=== TRADES ===\n";
echo "Totaal aantal trades: {$tradeCount}\n\n";

// Laatste 10 trades
echo "Laatste 10 trades:\n";
echo sprintf("%-12s %-10s %-5s %10s %12s %12s\n", 'Datum', 'Symbol', 'B/S', 'Aantal', 'Prijs', 'Bedrag');
echo str_repeat('-', 65) . "\n";

$allTrades = [];
foreach ($trades->Trade as $trade) {
    $allTrades[] = $trade;
}

$recentTrades = array_slice($allTrades, -10);
foreach ($recentTrades as $trade) {
    $date   = (string)$trade['tradeDate'];
    $symbol = (string)$trade['symbol'];
    $bs     = (string)$trade['buySell'];
    $qty    = number_format(abs((float)$trade['quantity']), 0, ',', '.');
    $price  = number_format((float)$trade['tradePrice'], 2, ',', '.');
    $amount = number_format((float)$trade['netCash'], 2, ',', '.');

    echo sprintf("%-12s %-10s %-5s %10s %12s %12s\n", $date, $symbol, $bs, $qty, $price, $amount);
}
