<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Database\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    #[Route('/api/v1/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $dbStatus = 'healthy';
        try {
            $this->connection->getPdo()->query('SELECT 1');
        } catch (\Throwable $e) {
            $dbStatus = 'error: ' . $e->getMessage();
        }

        return $this->json([
            'status' => $dbStatus === 'healthy' ? 'pass' : 'fail',
            'version' => '1.0.0',
            'service' => 'homelabstatus-uplink',
            'checks' => [
                'database' => $dbStatus,
            ],
            'timestamp' => gmdate('c'),
        ]);
    }
}
