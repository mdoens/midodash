<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\IbClient;
use App\Service\MomentumService;
use App\Service\SaxoClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    #[Route('/health', name: 'health')]
    public function __invoke(
        IbClient $ibClient,
        SaxoClient $saxoClient,
        MomentumService $momentumService,
    ): JsonResponse {
        $ibCacheFile = $ibClient->getCacheFile();
        $ibOk = file_exists($ibCacheFile) && (time() - filemtime($ibCacheFile)) < 7200;

        $saxoOk = $saxoClient->isAuthenticated();

        $momentumCacheFile = $momentumService->getCacheFile();
        $momentumOk = file_exists($momentumCacheFile) && (time() - filemtime($momentumCacheFile)) < 7200;

        $allOk = $ibOk && $saxoOk && $momentumOk;

        return new JsonResponse([
            'status' => $allOk ? 'ok' : 'degraded',
            'services' => [
                'ib' => [
                    'ok' => $ibOk,
                    'cache_age' => file_exists($ibCacheFile) ? time() - filemtime($ibCacheFile) : null,
                ],
                'saxo' => [
                    'ok' => $saxoOk,
                    'authenticated' => $saxoOk,
                ],
                'momentum' => [
                    'ok' => $momentumOk,
                    'cache_age' => file_exists($momentumCacheFile) ? time() - filemtime($momentumCacheFile) : null,
                ],
            ],
        ], $allOk ? 200 : 503);
    }
}
