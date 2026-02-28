<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

class TransactionImportService
{
    /** @var array<string, string> */
    private readonly array $symbolMap;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRepository $repository,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
        $file = $this->projectDir . '/config/mido_v65.yaml';
        $yaml = file_exists($file) ? Yaml::parseFile($file) : [];
        $this->symbolMap = $yaml['mido']['symbol_map'] ?? [];
    }

    /**
     * Import transactions from IB Flex XML content.
     * Parses <Trades><Trade .../></Trades> section.
     *
     * @return array{imported: int, skipped: int}
     */
    public function importFromIbXml(string $xml): array
    {
        $imported = 0;
        $skipped = 0;

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            $this->logger->warning('TransactionImport: invalid IB XML');

            return ['imported' => 0, 'skipped' => 0];
        }

        // IB Flex XML structure: FlexQueryResponse > FlexStatements > FlexStatement > Trades > Trade
        $trades = $doc->xpath('//Trade') ?: [];

        foreach ($trades as $trade) {
            $attrs = [];
            foreach ($trade->attributes() as $key => $val) {
                $attrs[(string) $key] = (string) $val;
            }

            $externalId = $attrs['tradeID'] ?? $attrs['transactionID'] ?? '';
            if ($externalId === '') {
                continue;
            }

            if ($this->repository->existsByPlatformAndExternalId('ib', $externalId)) {
                $skipped++;
                continue;
            }

            $tx = new Transaction();
            $tx->setPlatform('ib');
            $tx->setExternalId($externalId);

            $dateStr = $attrs['tradeDate'] ?? $attrs['dateTime'] ?? '';
            $timeStr = $attrs['tradeTime'] ?? '';
            $tx->setTradedAt($this->parseIbDateTime($dateStr, $timeStr));

            $symbol = $attrs['symbol'] ?? '';
            $tx->setSymbol($symbol);
            $tx->setPositionName($this->symbolMap[$symbol] ?? $attrs['description'] ?? '');

            // Determine type from buySell and assetCategory
            $buySell = strtolower($attrs['buySell'] ?? '');
            if (str_contains($buySell, 'buy')) {
                $tx->setType('buy');
            } elseif (str_contains($buySell, 'sell')) {
                $tx->setType('sell');
            } else {
                $tx->setType($buySell !== '' ? $buySell : 'unknown');
            }

            $tx->setQuantity((string) abs((float) ($attrs['quantity'] ?? '0')));
            $tx->setPrice($attrs['tradePrice'] ?? $attrs['price'] ?? '0');
            $tx->setAmount($attrs['proceeds'] ?? $attrs['netCash'] ?? '0');
            $tx->setCurrency($attrs['currency'] ?? 'EUR');
            $tx->setFxRate($attrs['fxRateToBase'] ?? '1');

            $proceeds = (float) ($attrs['proceeds'] ?? ($attrs['netCash'] ?? '0'));
            $fxRate = (float) ($attrs['fxRateToBase'] ?? '1');
            $tx->setAmountEur((string) ($proceeds * $fxRate));

            $tx->setCommission($attrs['ibCommission'] ?? $attrs['commission'] ?? '0');

            $this->em->persist($tx);
            $imported++;
        }

        // Also import CashTransactions (dividends, fees, deposits, withdrawals)
        $cashTxs = $doc->xpath('//CashTransaction') ?: [];
        foreach ($cashTxs as $cashTx) {
            $attrs = [];
            foreach ($cashTx->attributes() as $key => $val) {
                $attrs[(string) $key] = (string) $val;
            }

            $externalId = $attrs['transactionID'] ?? '';
            if ($externalId === '') {
                continue;
            }

            if ($this->repository->existsByPlatformAndExternalId('ib', $externalId)) {
                $skipped++;
                continue;
            }

            $tx = new Transaction();
            $tx->setPlatform('ib');
            $tx->setExternalId($externalId);
            $tx->setTradedAt($this->parseIbDateTime($attrs['dateTime'] ?? $attrs['reportDate'] ?? '', ''));
            $symbol = $attrs['symbol'] ?? '';
            $tx->setSymbol($symbol);
            $tx->setPositionName($this->symbolMap[$symbol] ?? $attrs['description'] ?? '');

            // Map IB cash transaction types
            $ibType = strtolower($attrs['type'] ?? '');
            if (str_contains($ibType, 'dividend')) {
                $tx->setType('dividend');
            } elseif (str_contains($ibType, 'withholding')) {
                $tx->setType('tax');
            } elseif (str_contains($ibType, 'commission')) {
                $tx->setType('fee');
            } elseif (str_contains($ibType, 'interest')) {
                $tx->setType('interest');
            } elseif (str_contains($ibType, 'deposit')) {
                $tx->setType('deposit');
            } elseif (str_contains($ibType, 'withdrawal')) {
                $tx->setType('withdrawal');
            } else {
                $tx->setType($ibType !== '' ? $ibType : 'other');
            }

            $amount = (float) ($attrs['amount'] ?? '0');
            $tx->setQuantity('0');
            $tx->setPrice('0');
            $tx->setAmount((string) $amount);
            $tx->setCurrency($attrs['currency'] ?? 'EUR');

            $fxRate = (float) ($attrs['fxRateToBase'] ?? '1');
            $tx->setFxRate((string) $fxRate);
            $tx->setAmountEur((string) ($amount * $fxRate));
            $tx->setCommission('0');

            $this->em->persist($tx);
            $imported++;
        }

        if ($imported > 0) {
            $this->em->flush();
        }

        $this->logger->info('IB transactions imported', ['imported' => $imported, 'skipped' => $skipped]);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Delete all transactions for a given platform (for re-import).
     */
    public function deletePlatformTransactions(string $platform): int
    {
        $conn = $this->em->getConnection();
        $table = $conn->quoteIdentifier('transaction');

        $deleted = $conn->executeStatement(
            'DELETE FROM ' . $table . ' WHERE platform = :platform',
            ['platform' => $platform],
        );

        $this->logger->info('Deleted platform transactions', ['platform' => $platform, 'deleted' => $deleted]);

        return $deleted;
    }

    /**
     * Update positionName for all existing transactions using the symbol_map
     * and description-based patterns for Saxo fund names.
     */
    public function remapPositionNames(): int
    {
        $updated = 0;
        $conn = $this->em->getConnection();
        $tableName = $conn->quoteIdentifier('transaction');

        // Remap by symbol
        foreach ($this->symbolMap as $symbol => $name) {
            $count = $conn->executeStatement(
                'UPDATE ' . $tableName . ' SET position_name = :name WHERE symbol = :symbol AND position_name != :name',
                ['name' => $name, 'symbol' => $symbol],
            );
            $updated += $count;
        }

        // Remap long descriptions and old names to short ticker codes
        $descriptionMap = [
            // Saxo fund descriptions → ticker codes
            '%NORTHERN TRUST WORLD%' => 'NTWC',
            '%NORTHERN TRUST%EMERGING MARKET%' => 'NTEM',
            '%NORTHERN TRUST%EUROPE%' => 'NTEU',
            '%NT EMERGING%' => 'NTEM',
            '%NT EUROPE%' => 'NTEU',
            // Old position names → new ticker codes
            'NT World' => 'NTWC',
            'NT EM' => 'NTEM',
            'NT Europe' => 'NTEU',
            // IB/Saxo long descriptions → ticker codes
            '%Ultrashort Bond%' => 'XEON',
            '%Overnight Rate%' => 'XEON',
            '%Euro Government Bond 1-3%' => 'IBGS',
            '%Physical Gold%' => 'EGLN',
            '%AVANTIS%GLOBAL EQ%' => 'AVWC',
            '%AVANTIS%SMALL%' => 'AVWS',
            '%AVANTIS%EM%' => 'AVEM',
            '%BITWISE%BITCOIN%' => 'Crypto',
            '%WISDOMTREE%ETHEREUM%' => 'Crypto',
            '%21SHARES%SOLANA%' => 'Crypto',
            '%SPDR%EUROPE%VALUE%' => 'ZPRX',
        ];

        foreach ($descriptionMap as $pattern => $name) {
            $count = $conn->executeStatement(
                'UPDATE ' . $tableName . ' SET position_name = :name WHERE position_name LIKE :pattern AND position_name != :name',
                ['name' => $name, 'pattern' => $pattern],
            );
            $updated += $count;
        }

        if ($updated > 0) {
            $this->logger->info('Remapped position names', ['updated' => $updated]);
        }

        return $updated;
    }

    private function parseIbDateTime(string $date, string $time): \DateTime
    {
        // IB formats: "20260227" or "2026-02-27" or "20260227;153000"
        $date = str_replace(['-', ';'], '', trim($date));

        if (strlen($date) >= 14) {
            // Has time component: YYYYMMDDHHMMSS
            $dt = \DateTime::createFromFormat('YmdHis', substr($date, 0, 14));
        } elseif (strlen($date) === 8) {
            $combined = $date . ($time !== '' ? str_replace(':', '', $time) : '120000');
            $dt = \DateTime::createFromFormat('YmdHis', $combined);
        } else {
            $dt = false;
        }

        return $dt instanceof \DateTime ? $dt : new \DateTime();
    }

    /**
     * Import from Saxo trade reports (/cs/v1/reports/trades).
     *
     * @param list<array<string, mixed>> $trades from Saxo API
     *
     * @return array{imported: int, skipped: int}
     */
    public function importFromSaxoOrders(array $trades): array
    {
        $imported = 0;
        $skipped = 0;

        foreach ($trades as $trade) {
            // Trade reports use TradeId; fallback to OrderId
            $externalId = (string) ($trade['TradeId'] ?? $trade['OrderId'] ?? '');
            if ($externalId === '') {
                continue;
            }

            if ($this->repository->existsByPlatformAndExternalId('saxo', $externalId)) {
                $skipped++;
                continue;
            }

            $tx = new Transaction();
            $tx->setPlatform('saxo');
            $tx->setExternalId($externalId);

            $dateStr = (string) ($trade['TradeExecutionTime'] ?? $trade['ExecutionTime'] ?? $trade['TradeDate'] ?? '');
            $tx->setTradedAt($dateStr !== '' ? new \DateTime($dateStr) : new \DateTime());

            $symbol = (string) ($trade['InstrumentSymbol'] ?? $trade['Symbol'] ?? ($trade['Uic'] ?? ''));
            // Strip exchange suffix for symbol_map lookup (e.g. "IBGS:xams" → "IBGS")
            $baseSymbol = str_contains($symbol, ':') ? explode(':', $symbol)[0] : $symbol;
            $tx->setSymbol($baseSymbol);
            $tx->setPositionName($this->symbolMap[$baseSymbol] ?? $this->symbolMap[$symbol] ?? (string) ($trade['InstrumentDescription'] ?? ''));

            // Determine buy/sell: Direction can be "Bought"/"Sold"/"None"
            // When Direction is "None" (e.g. mutual fund subscriptions), use TradedValue sign:
            // negative = money out = buy, positive = money in = sell
            $direction = strtolower((string) ($trade['BuySell'] ?? $trade['Direction'] ?? ''));
            if (str_contains($direction, 'buy') || str_contains($direction, 'bought')) {
                $tx->setType('buy');
            } elseif (str_contains($direction, 'sell') || str_contains($direction, 'sold')) {
                $tx->setType('sell');
            } else {
                // Fallback: TradedValue sign (negative = buy, positive = sell)
                $tradedVal = (float) ($trade['TradedValue'] ?? 0);
                $tx->setType($tradedVal < 0 ? 'buy' : 'sell');
            }

            $qty = abs((float) ($trade['Amount'] ?? 0));
            $price = (float) ($trade['Price'] ?? 0);
            $tx->setQuantity((string) $qty);
            $tx->setPrice((string) $price);

            $tradedValue = abs((float) ($trade['TradedValue'] ?? ($qty * $price)));
            $tx->setAmount((string) $tradedValue);

            $currency = (string) ($trade['AccountCurrency'] ?? $trade['ClientCurrency'] ?? 'EUR');
            $tx->setCurrency($currency);

            // FX conversion: Saxo provides BookedAmountClientCurrency for account-currency total
            $amountEur = abs((float) ($trade['BookedAmountClientCurrency'] ?? $trade['BookedAmountAccountCurrency'] ?? $tradedValue));
            $fxRate = $tradedValue > 0 ? $amountEur / $tradedValue : 1.0;
            $tx->setFxRate((string) $fxRate);
            $tx->setAmountEur((string) $amountEur);

            $tx->setCommission((string) abs((float) ($trade['SpreadCostClientCurrency'] ?? $trade['SpreadCostAccountCurrency'] ?? $trade['Commission'] ?? 0)));

            $this->em->persist($tx);
            $imported++;
        }

        if ($imported > 0) {
            $this->em->flush();
        }

        $this->logger->info('Saxo transactions imported', ['imported' => $imported, 'skipped' => $skipped]);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Import cash transactions from Saxo /hist/v1/transactions endpoint.
     * These include dividends, interest, fees, deposits, withdrawals.
     *
     * @param list<array<string, mixed>> $transactions from Saxo API
     *
     * @return array{imported: int, skipped: int}
     */
    public function importFromSaxoCashTransactions(array $transactions): array
    {
        $imported = 0;
        $skipped = 0;

        foreach ($transactions as $tx) {
            $externalId = (string) ($tx['TransactionId'] ?? $tx['BookingId'] ?? $tx['BkRecordId'] ?? '');
            if ($externalId === '') {
                continue;
            }

            // Prefix with 'cash_' to avoid collision with trade IDs
            $externalId = 'cash_' . $externalId;

            if ($this->repository->existsByPlatformAndExternalId('saxo', $externalId)) {
                $skipped++;
                continue;
            }

            $transaction = new Transaction();
            $transaction->setPlatform('saxo');
            $transaction->setExternalId($externalId);

            $dateStr = (string) ($tx['ValueDate'] ?? $tx['Date'] ?? $tx['AccountValueDate'] ?? '');
            $transaction->setTradedAt($dateStr !== '' ? new \DateTime($dateStr) : new \DateTime());

            // Saxo nests instrument data in 'Instrument' object
            $instrument = $tx['Instrument'] ?? [];
            $symbol = (string) ($instrument['Symbol'] ?? $tx['InstrumentSymbol'] ?? $tx['Symbol'] ?? '');
            $baseSymbol = str_contains($symbol, ':') ? explode(':', $symbol)[0] : $symbol;
            $transaction->setSymbol($baseSymbol);
            $description = (string) ($instrument['Description'] ?? $tx['InstrumentDescription'] ?? $tx['Description'] ?? '');
            $transaction->setPositionName(
                $this->symbolMap[$baseSymbol] ?? $this->symbolMap[$symbol] ?? $description
            );

            // Map Saxo transaction types — use Event field as fallback (more specific)
            $txType = strtolower((string) ($tx['TransactionType'] ?? ''));
            $event = strtolower((string) ($tx['Event'] ?? ''));
            if (str_contains($txType, 'corporateaction') || str_contains($event, 'dividend')) {
                $transaction->setType('dividend');
            } elseif (str_contains($txType, 'interest') || str_contains($event, 'interest')) {
                $transaction->setType('interest');
            } elseif (str_contains($txType, 'commission') || str_contains($event, 'fee') || str_contains($txType, 'cashamount')) {
                $transaction->setType('fee');
            } elseif (str_contains($txType, 'cashtransfer') || str_contains($event, 'deposit')) {
                $transaction->setType('deposit');
            } elseif (str_contains($event, 'withdrawal')) {
                $transaction->setType('withdrawal');
            } elseif (str_contains($txType, 'tax') || str_contains($event, 'withholding')) {
                $transaction->setType('tax');
            } else {
                // Skip trade-related transactions (already imported via importFromSaxoOrders)
                if (str_contains($txType, 'trade') || str_contains($txType, 'exercise') || str_contains($txType, 'settlement')) {
                    continue;
                }
                $transaction->setType($txType !== '' ? $txType : 'other');
            }

            // Amount: BookedAmount (net) or IntradayAmount (for intraday/deposits) or Amount fallback
            $amount = (float) ($tx['BookedAmount'] ?? $tx['IntradayAmount'] ?? $tx['Amount'] ?? $tx['CashAmount'] ?? 0);
            $transaction->setQuantity('0');
            $transaction->setPrice('0');
            $transaction->setAmount((string) $amount);

            $currency = (string) ($tx['Currency'] ?? $tx['AccountCurrency'] ?? 'EUR');
            $transaction->setCurrency($currency);

            // Saxo transactions are typically already in account currency (EUR)
            $transaction->setFxRate((string) ($tx['ConversionRate'] ?? 1));
            $transaction->setAmountEur((string) $amount);
            $transaction->setCommission('0');

            $this->em->persist($transaction);
            $imported++;
        }

        if ($imported > 0) {
            $this->em->flush();
        }

        $this->logger->info('Saxo cash transactions imported', ['imported' => $imported, 'skipped' => $skipped]);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Delete all Saxo cash transactions (prefix 'cash_') for re-import.
     */
    public function deleteSaxoCashTransactions(): int
    {
        $conn = $this->em->getConnection();
        $deleted = (int) $conn->executeStatement(
            'DELETE FROM ' . $conn->quoteIdentifier('transaction') . ' WHERE platform = :platform AND external_id LIKE :prefix',
            ['platform' => 'saxo', 'prefix' => 'cash_%']
        );
        $this->logger->info('Deleted Saxo cash transactions for re-import', ['deleted' => $deleted]);

        return $deleted;
    }
}
