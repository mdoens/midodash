<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TransactionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TransactionController extends AbstractController
{
    #[Route('/transactions', name: 'transactions')]
    public function index(
        TransactionRepository $repository,
        Request $request,
    ): Response {
        $platform = $request->query->getString('platform', '');
        $type = $request->query->getString('type', '');
        $from = $request->query->getString('from', '');
        $to = $request->query->getString('to', '');

        $fromDate = $from !== '' ? new \DateTime($from) : null;
        $toDate = $to !== '' ? new \DateTime($to) : null;

        $transactions = $repository->findFiltered(
            $platform !== '' ? $platform : null,
            $type !== '' ? $type : null,
            $fromDate,
            $toDate,
            500,
        );

        // Calculate summary
        $totalBuy = 0.0;
        $totalSell = 0.0;
        $totalDividend = 0.0;
        $totalCommission = 0.0;

        foreach ($transactions as $tx) {
            $eur = abs((float) $tx->getAmountEur());
            match ($tx->getType()) {
                'buy' => $totalBuy += $eur,
                'sell' => $totalSell += $eur,
                'dividend' => $totalDividend += $eur,
                default => null,
            };
            $totalCommission += abs((float) $tx->getCommission());
        }

        return $this->render('transaction/index.html.twig', [
            'transactions' => $transactions,
            'filters' => [
                'platform' => $platform,
                'type' => $type,
                'from' => $from,
                'to' => $to,
            ],
            'summary' => [
                'total_buy' => $totalBuy,
                'total_sell' => $totalSell,
                'total_dividend' => $totalDividend,
                'total_commission' => $totalCommission,
                'net' => $totalSell - $totalBuy + $totalDividend,
            ],
        ]);
    }
}
