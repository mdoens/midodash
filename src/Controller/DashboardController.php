<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\IbClient;
use App\Service\MomentumService;
use App\Service\SaxoClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function index(
        IbClient $ibClient,
        SaxoClient $saxoClient,
        MomentumService $momentumService,
    ): Response {
        // IB posities
        $ibPositions = $ibClient->getPositions();
        $ibCash = $ibClient->getCashReport();
        $ibTotalValue = array_sum(array_column($ibPositions, 'value'));
        $ibTotalCost = array_sum(array_column($ibPositions, 'cost'));
        $ibCashBalance = $ibCash['ending_cash'] ?? 0.0;

        // Saxo posities (null als niet ingelogd)
        $saxoPositions = null;
        $saxoBalance = null;
        $saxoAuthenticated = $saxoClient->isAuthenticated();
        if ($saxoAuthenticated) {
            $saxoPositions = $saxoClient->getPositions();
            $saxoBalance = $saxoClient->getAccountBalance();
            if ($saxoPositions === null) {
                $saxoAuthenticated = false;
            }
        }

        $saxoTotalPnl = $saxoPositions !== null ? array_sum(array_column($saxoPositions, 'pnl')) : 0;
        $saxoTotalExposure = $saxoPositions !== null ? array_sum(array_column($saxoPositions, 'exposure')) : 0;
        $saxoCashBalance = (float) ($saxoBalance['CashBalance'] ?? 0);

        // Momentum signaal
        $signal = $momentumService->getSignal();

        // Grand total (posities + cash)
        $grandTotal = $ibTotalValue + $ibCashBalance + $saxoTotalExposure + $saxoCashBalance;

        return $this->render('dashboard/index.html.twig', [
            'ib_positions' => $ibPositions,
            'ib_cash' => $ibCash,
            'ib_total_value' => $ibTotalValue,
            'ib_total_cost' => $ibTotalCost,
            'ib_cash_balance' => $ibCashBalance,
            'saxo_authenticated' => $saxoAuthenticated,
            'saxo_positions' => $saxoPositions,
            'saxo_total_pnl' => $saxoTotalPnl,
            'saxo_total_exposure' => $saxoTotalExposure,
            'saxo_cash_balance' => $saxoCashBalance,
            'signal' => $signal,
            'grand_total' => $grandTotal,
        ]);
    }
}
