<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\AuditLogRepository;
use App\Repository\CheckExecutionRepository;
use App\Repository\CheckRepository;
use App\Service\Tui\SparklineGenerator;
use App\Service\UplinkMonitorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/uplink')]
class UplinkController extends AbstractController
{
    public function __construct(
        private readonly UplinkMonitorService $monitorService,
        private readonly CheckRepository $checkRepository,
        private readonly CheckExecutionRepository $executionRepository,
        private readonly AuditLogRepository $auditLogRepository
    ) {}

    /**
     * Get current uplink status and composite score
     */
    #[Route('', name: 'api_uplink_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $summary = $this->monitorService->getCurrentStatus();
        return $this->json([
            'status' => 'ok',
            'data' => $summary->toArray(),
        ]);
    }

    /**
     * Server-Sent Events (SSE) live streaming endpoint for real-time dashboard updates
     */
    #[Route('/stream', name: 'api_uplink_stream', methods: ['GET'])]
    public function liveStream(Request $request): StreamedResponse
    {
        $interval = max(2, min(30, (int)$request->query->get('interval', 3)));
        $packets = max(1, min(5, (int)$request->query->get('packets', 2)));

        $response = new StreamedResponse(function () use ($interval, $packets) {
            if (function_exists('apache_setenv')) {
                @apache_setenv('no-gzip', '1');
            }
            @ini_set('zlib.output_compression', '0');
            @ini_set('implicit_flush', '1');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            for ($i = 0; $i < 60; $i++) {
                if (connection_aborted()) {
                    break;
                }

                $summary = $this->monitorService->probeAll($packets);
                $history = $this->monitorService->getHistoryWithSparklines(20);

                $payload = json_encode([
                    'summary' => $summary->toArray(),
                    'history' => $history,
                    'timestamp' => gmdate('c'),
                ]);

                echo "event: update\n";
                echo "data: {$payload}\n\n";
                flush();

                sleep($interval);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    /**
     * Trigger immediate live ping probes to all targets
     */
    #[Route('/ping', name: 'api_uplink_ping_now', methods: ['POST'])]
    public function pingNow(Request $request): JsonResponse
    {
        $packets = max(1, min(10, (int)$request->query->get('packets', 2)));
        $summary = $this->monitorService->probeAll($packets);

        return $this->json([
            'status' => 'ok',
            'message' => 'Probes executed successfully',
            'data' => $summary->toArray(),
        ]);
    }

    /**
     * List all monitored checks of type 'uplink'
     */
    #[Route('/checks', name: 'api_uplink_checks', methods: ['GET'])]
    public function checks(): JsonResponse
    {
        $checks = $this->checkRepository->findByType('uplink', onlyEnabled: false);
        $checkIds = array_map(fn($c) => $c->id, $checks);
        $latestExecs = $this->executionRepository->getLatestForChecks($checkIds);

        $data = [];
        foreach ($checks as $check) {
            $latest = $latestExecs[$check->id] ?? null;
            $data[] = [
                'check' => $check->toArray(),
                'latest_execution' => $latest?->toArray(),
            ];
        }

        return $this->json([
            'status' => 'ok',
            'count' => count($data),
            'data' => $data,
        ]);
    }

    /**
     * Get detailed history and sparkline for a specific check
     */
    #[Route('/checks/{id}', name: 'api_uplink_check_detail', methods: ['GET'])]
    public function checkDetail(string $id, Request $request): JsonResponse
    {
        $check = $this->checkRepository->findById($id) ?? $this->checkRepository->findBySlug($id);
        if (!$check) {
            return $this->json([
                'status' => 'error',
                'message' => "Check '{$id}' not found",
            ], Response::HTTP_NOT_FOUND);
        }

        $limit = max(5, min(120, (int)$request->query->get('limit', 50)));
        $history = $this->executionRepository->getHistoryForCheck($check->id, $limit);

        $latencies = [];
        foreach ($history as $exec) {
            if (isset($exec->resultData['avg_latency_ms']) && $exec->resultData['avg_latency_ms'] !== null) {
                $latencies[] = (float)$exec->resultData['avg_latency_ms'];
            }
        }

        $sparkline = SparklineGenerator::generate($latencies, 30);
        $latest = !empty($history) ? end($history) : null;

        return $this->json([
            'status' => 'ok',
            'check' => $check->toArray(),
            'latest' => $latest?->toArray(),
            'sparkline' => $sparkline,
            'samples_count' => count($history),
            'history' => array_map(fn($e) => $e->toArray(), $history),
        ]);
    }

    /**
     * Get historical time-series data & sparklines for all checks
     */
    #[Route('/history', name: 'api_uplink_history', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        $samples = max(5, min(60, (int)$request->query->get('samples', 30)));
        $historyData = $this->monitorService->getHistoryWithSparklines($samples);

        return $this->json([
            'status' => 'ok',
            'data' => $historyData,
        ]);
    }

    /**
     * Get recent audit / activity logs
     */
    #[Route('/audit-logs', name: 'api_uplink_audit_logs', methods: ['GET'])]
    public function auditLogs(Request $request): JsonResponse
    {
        $limit = max(5, min(100, (int)$request->query->get('limit', 50)));
        $logs = $this->auditLogRepository->getRecent($limit);

        return $this->json([
            'status' => 'ok',
            'count' => count($logs),
            'data' => array_map(fn($l) => $l->toArray(), $logs),
        ]);
    }
}
