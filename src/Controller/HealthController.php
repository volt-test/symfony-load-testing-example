<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Predis\Client as Redis;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(Connection $db, Redis $redis): JsonResponse
    {
        $checks = ['database' => false, 'redis' => false];

        try {
            $db->executeQuery('SELECT 1')->fetchOne();
            $checks['database'] = true;
        } catch (\Throwable) {
        }

        try {
            $checks['redis'] = (string) $redis->ping() === 'PONG';
        } catch (\Throwable) {
        }

        $ok = !in_array(false, $checks, true);

        return new JsonResponse(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks], $ok ? 200 : 503);
    }
}
