<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class TransactionImportService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRepository $repository,
        private readonly LoggerInterface $logger,
    ) {}

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

            $tx->setSymbol($attrs['symbol'] ?? '');
            $tx->setPositionName($attrs['description'] ?? '');

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
            $tx->setSymbol($attrs['symbol'] ?? '');
            $tx->setPositionName($attrs['description'] ?? '');

            // Map IB cash transaction types
            $ibType = strtolower($attrs['type'] ?? '');
            if (str_contains($ibType, 'dividend')) {
                $tx->setType('dividend');
            } elseif (str_contains($ibType, 'withholding')) {
                $tx->setType('tax');
            } elseif (str_contains($ibType, 'commission')) {
                $tx->setType('fee');
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
     * Import from Saxo order history.
     *
     * @param list<array<string, mixed>> $orders from Saxo API
     * @return array{imported: int, skipped: int}
     */
    public function importFromSaxoOrders(array $orders): array
    {
        $imported = 0;
        $skipped = 0;

        foreach ($orders as $order) {
            $externalId = (string) ($order['OrderId'] ?? '');
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

            $dateStr = (string) ($order['ExecutionTime'] ?? $order['OrderTime'] ?? '');
            $tx->setTradedAt($dateStr !== '' ? new \DateTime($dateStr) : new \DateTime());

            /** @var array<string, string> $displayFormat */
            $displayFormat = $order['DisplayAndFormat'] ?? [];
            $tx->setSymbol((string) ($displayFormat['Symbol'] ?? ($order['Uic'] ?? '')));
            $tx->setPositionName((string) ($displayFormat['Description'] ?? ''));

            $buySell = strtolower((string) ($order['BuySell'] ?? ''));
            $tx->setType(str_contains($buySell, 'buy') ? 'buy' : 'sell');

            $tx->setQuantity((string) abs((float) ($order['Amount'] ?? 0)));
            $tx->setPrice((string) ($order['Price'] ?? $order['FilledPrice'] ?? 0));

            $amount = (float) ($order['Amount'] ?? 0) * (float) ($order['Price'] ?? $order['FilledPrice'] ?? 0);
            $tx->setAmount((string) $amount);
            $tx->setCurrency((string) ($displayFormat['Currency'] ?? 'EUR'));
            $tx->setFxRate('1');
            $tx->setAmountEur((string) $amount);
            $tx->setCommission((string) ($order['Commission'] ?? 0));

            $this->em->persist($tx);
            $imported++;
        }

        if ($imported > 0) {
            $this->em->flush();
        }

        $this->logger->info('Saxo transactions imported', ['imported' => $imported, 'skipped' => $skipped]);

        return ['imported' => $imported, 'skipped' => $skipped];
    }
}
