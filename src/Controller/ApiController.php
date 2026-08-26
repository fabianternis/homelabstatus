<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CheckResultRepository;
use App\Repository\IncidentRepository;
use App\Repository\MonitorRepository;
use App\Service\MonitorRunner;
use App\Service\SystemMetricsCollector;
use App\Service\UptimeCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ApiController extends AbstractController
{
    public function __construct(
        private readonly MonitorRepository $monitorRepository,
        private readonly CheckResultRepository $checkResultRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly UptimeCalculator $uptimeCalculator,
        private readonly MonitorRunner $monitorRunner,
        private readonly SystemMetricsCollector $metricsCollector
    ) {}

    private function checkAuth(Request $request): bool
    {
        $apiKey = $_ENV['API_KEY'] ?? '';
        if (empty($apiKey)) {
            return true;
        }

        $authHeader = $request->headers->get('Authorization', '');
        return ($authHeader === "Bearer {$apiKey}" || $request->query->get('api_key') === $apiKey);
    }

    #[Route('/status', name: 'api_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $summary = $this->uptimeCalculator->getGlobalSystemSummary();
        $activeIncidents = $this->incidentRepository->findActive();
        $monitors = $this->monitorRepository->findAllActive();

        $monitorList = [];
        foreach ($monitors as $m) {
            $uptimes = $this->uptimeCalculator->calculateUptime($m->id);
            $data = $m->toArray();
            $data['uptime_24h'] = $uptimes['24h'];
            $data['uptime_30d'] = $uptimes['30d'];
            $data['avg_latency_ms'] = $uptimes['avg_latency'];
            $monitorList[] = $data;
        }

        return $this->json([
            'status' => 'ok',
            'overall_status' => $summary['status']->value,
            'summary' => $summary,
            'monitors_count' => count($monitorList),
            'active_incidents' => array_map(fn($inc) => [
                'id' => $inc->id,
                'title' => $inc->title,
                'severity' => $inc->severity->value,
                'status' => $inc->status,
                'message' => $inc->message,
                'started_at' => $inc->startedAt,
            ], $activeIncidents),
            'monitors' => $monitorList,
            'timestamp' => gmdate('c'),
        ]);
    }

    #[Route('/monitors', name: 'api_monitors', methods: ['GET'])]
    public function monitors(): JsonResponse
    {
        $monitors = $this->monitorRepository->findAllActive();
        return $this->json([
            'status' => 'ok',
            'data' => array_map(fn($m) => $m->toArray(), $monitors),
        ]);
    }

    #[Route('/monitors/{id}', name: 'api_monitor_show', methods: ['GET'])]
    public function showMonitor(int $id): JsonResponse
    {
        $monitor = $this->monitorRepository->find($id);
        if (!$monitor) {
            return $this->json(['error' => 'Monitor not found'], Response::HTTP_NOT_FOUND);
        }

        $uptimes = $this->uptimeCalculator->calculateUptime($monitor->id);
        $recentChecks = $this->checkResultRepository->getRecentForMonitor($monitor->id, 50);

        return $this->json([
            'status' => 'ok',
            'monitor' => $monitor->toArray(),
            'uptime' => $uptimes,
            'recent_checks' => $recentChecks,
        ]);
    }

    #[Route('/check', name: 'api_check_all', methods: ['POST'])]
    public function runChecks(Request $request): JsonResponse
    {
        if (!$this->checkAuth($request)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $results = $this->monitorRunner->runAll();
        $summary = [];
        foreach ($results as $monitorId => $res) {
            $summary[$monitorId] = [
                'status' => $res->status->value,
                'response_time_ms' => $res->responseTimeMs,
                'status_code' => $res->statusCode,
                'error' => $res->errorMessage,
            ];
        }

        return $this->json([
            'status' => 'ok',
            'checks_executed' => count($results),
            'results' => $summary,
            'timestamp' => gmdate('c'),
        ]);
    }

    #[Route('/metrics', name: 'api_metrics', methods: ['GET'])]
    public function metrics(): JsonResponse
    {
        $metric = $this->metricsCollector->collectAndSave();
        return $this->json([
            'status' => 'ok',
            'data' => [
                'cpu_usage_percent' => $metric->cpuUsagePercent,
                'memory_used_bytes' => $metric->memoryUsedBytes,
                'memory_total_bytes' => $metric->memoryTotalBytes,
                'memory_percent' => $metric->memoryTotalBytes > 0
                    ? round(($metric->memoryUsedBytes / $metric->memoryTotalBytes) * 100, 1)
                    : 0,
                'disk_used_bytes' => $metric->diskUsedBytes,
                'disk_total_bytes' => $metric->diskTotalBytes,
                'disk_percent' => $metric->diskTotalBytes > 0
                    ? round(($metric->diskUsedBytes / $metric->diskTotalBytes) * 100, 1)
                    : 0,
                'load_avg' => [
                    '1m' => $metric->load1m,
                    '5m' => $metric->load5m,
                    '15m' => $metric->load15m,
                ],
                'uptime_seconds' => $metric->uptimeSeconds,
            ],
        ]);
    }

    #[Route('/incidents', name: 'api_incidents', methods: ['GET'])]
    public function incidents(): JsonResponse
    {
        $incidents = $this->incidentRepository->findRecent(20);
        return $this->json([
            'status' => 'ok',
            'data' => array_map(fn($inc) => [
                'id' => $inc->id,
                'monitor_id' => $inc->monitorId,
                'monitor_name' => $inc->monitorName,
                'title' => $inc->title,
                'severity' => $inc->severity->value,
                'status' => $inc->status,
                'message' => $inc->message,
                'started_at' => $inc->startedAt,
                'resolved_at' => $inc->resolvedAt,
            ], $incidents),
        ]);
    }
}
