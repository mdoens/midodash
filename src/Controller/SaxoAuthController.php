<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DashboardCacheService;
use App\Service\SaxoClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SaxoAuthController extends AbstractController
{
    #[Route('/saxo/login', name: 'saxo_login')]
    public function login(SaxoClient $saxoClient, Request $request): Response
    {
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set('saxo_state', $state);

        return $this->redirect($saxoClient->getAuthUrl($state));
    }

    #[Route('/saxo/callback', name: 'saxo_callback')]
    public function callback(SaxoClient $saxoClient, DashboardCacheService $dashboardCache, Request $request): Response
    {
        $code = $request->query->get('code');

        if ($code === null) {
            $error = $request->query->get('error', 'onbekend');
            $this->addFlash('error', 'Saxo login mislukt: ' . $error);

            return $this->redirectToRoute('dashboard');
        }

        // Skip state validation if session was lost (e.g. after deploy)
        $state = $request->query->get('state', '');
        $expectedState = $request->getSession()->get('saxo_state', '');
        $request->getSession()->remove('saxo_state');

        if ($expectedState !== '' && $state !== $expectedState) {
            $this->addFlash('error', 'Ongeldige state parameter — mogelijke CSRF-aanval.');

            return $this->redirectToRoute('dashboard');
        }

        try {
            $saxoClient->exchangeCode($code);
            // Invalidate dashboard cache so Saxo data is fetched fresh
            $dashboardCache->invalidate();

            // Verify authentication works after token exchange
            if ($saxoClient->isAuthenticated()) {
                $this->addFlash('success', 'Saxo login succesvol! Tokens opgeslagen.');
            } else {
                $this->addFlash('error', 'Saxo tokens opgeslagen maar authenticatie-check faalt — controleer logs.');
            }
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Token exchange mislukt: ' . $e->getMessage());
        }

        return $this->redirectToRoute('dashboard');
    }

    #[Route('/saxo/refresh', name: 'saxo_refresh')]
    public function refresh(SaxoClient $saxoClient): Response
    {
        $result = $saxoClient->refreshToken();

        if ($result !== null) {
            $this->addFlash('success', 'Token vernieuwd.');
        } else {
            $this->addFlash('error', 'Token refresh mislukt. Log opnieuw in.');
        }

        return $this->redirectToRoute('dashboard');
    }
}
