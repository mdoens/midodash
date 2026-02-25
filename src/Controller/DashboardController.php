<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\IbClient;
use App\Service\MomentumService;
use App\Service\SaxoClient;
use Psr\Log\LoggerInterface;
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
        LoggerInterface $logger,
    ): Response {
        $ibError = false;
        $saxoError = false;
        $momentumError = false;

        try {
            $ibPositions = $ibClient->getPositions();
            $ibCash = $ibClient->getCashReport();
        } catch (\Throwable $e) {
            $logger->error('Dashboard: IB data failed', ['error' => $e->getMessage()]);
            $ibPositions = [];
            $ibCash = [];
            $ibError = true;
        }

        $ibTotalValue = array_sum(array_column($ibPositions, 'value'));
        $ibTotalCost = array_sum(array_column($ibPositions, 'cost'));
        $ibCashBalance = $ibCash['ending_cash'] ?? 0.0;

        $saxoPositions = null;
        $saxoBalance = null;
        $saxoAuthenticated = false;

        try {
            $saxoAuthenticated = $saxoClient->isAuthenticated();
            if ($saxoAuthenticated) {
                $saxoPositions = $saxoClient->getPositions();
                $saxoBalance = $saxoClient->getAccountBalance();
                if ($saxoPositions === null) {
                    $saxoAuthenticated = false;
                }
            }
        } catch (\Throwable $e) {
            $logger->error('Dashboard: Saxo data failed', ['error' => $e->getMessage()]);
            $saxoError = true;
        }

        $saxoTotalPnl = $saxoPositions !== null ? array_sum(array_column($saxoPositions, 'pnl')) : 0;
        $saxoTotalExposure = $saxoPositions !== null ? array_sum(array_column($saxoPositions, 'exposure')) : 0;
        $saxoCashBalance = (float) ($saxoBalance['CashBalance'] ?? 0);

        $signal = ['regime' => ['bull' => true, 'price' => 0, 'ma200' => 0], 'scores' => [], 'allocation' => [], 'reason' => ''];

        try {
            $signal = $momentumService->getSignal();
        } catch (\Throwable $e) {
            $logger->error('Dashboard: Momentum data failed', ['error' => $e->getMessage()]);
            $momentumError = true;
        }

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
            'ib_error' => $ibError,
            'saxo_error' => $saxoError,
            'momentum_error' => $momentumError,
        ]);
    }
}
