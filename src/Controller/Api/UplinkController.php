<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\PingResultDto;
use App\Repository\ProbeLogRepository;
use App\Repository\TargetRepository;
use App\Service\Tui\SparklineGenerator;
use App\Service\UplinkMonitorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/uplink')]
class UplinkController extends AbstractController
{
    public function __construct(
        private readonly UplinkMonitorService $monitorService,
        private readonly TargetRepository $targetRepository,
        private readonly ProbeLogRepository $probeLogRepository
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
     * Trigger immediate live ping probes to all targets
     */
    #[Route('/ping', name: 'api_uplink_ping_now', methods: ['POST'])]
    public function pingNow(Request $request): JsonResponse
    {
        $packets = max(1, min(10, (int)$request->query->get('packets', 3)));
        $summary = $this->monitorService->probeAll($packets);

        return $this->json([
            'status' => 'ok',
            'message' => 'Probes executed successfully',
            'data' => $summary->toArray(),
        ]);
    }

    /**
     * List all monitored external targets
     */
    #[Route('/targets', name: 'api_uplink_targets', methods: ['GET'])]
    public function targets(): JsonResponse
    {
        $targets = $this->targetRepository->getActiveTargets();
        $latestProbes = $this->probeLogRepository->getLatestForAllTargets();

        $data = [];
        foreach ($targets as $target) {
            $latest = $latestProbes[$target->id] ?? null;
            $data[] = [
                'target' => $target->toArray(),
                'latest_probe' => $latest?->toArray(),
            ];
        }

        return $this->json([
            'status' => 'ok',
            'count' => count($data),
            'data' => $data,
        ]);
    }

    /**
     * Get detailed history and sparkline for a specific target
     */
    #[Route('/targets/{id}', name: 'api_uplink_target_detail', methods: ['GET'])]
    public function targetDetail(string $id, Request $request): JsonResponse
    {
        $target = $this->targetRepository->findById($id);
        if (!$target) {
            return $this->json([
                'status' => 'error',
                'message' => "Target '{$id}' not found",
            ], Response::HTTP_NOT_FOUND);
        }

        $limit = max(5, min(120, (int)$request->query->get('limit', 50)));
        $history = $this->probeLogRepository->getHistoryForTarget($target->id, $limit);

        $latencies = [];
        foreach ($history as $log) {
            if ($log->avgLatencyMs !== null) {
                $latencies[] = $log->avgLatencyMs;
            }
        }

        $sparkline = SparklineGenerator::generate($latencies, 30);
        $latest = !empty($history) ? end($history) : null;

        return $this->json([
            'status' => 'ok',
            'target' => $target->toArray(),
            'latest' => $latest?->toArray(),
            'sparkline' => $sparkline,
            'samples_count' => count($history),
            'history' => array_map(fn(PingResultDto $l) => $l->toArray(), $history),
        ]);
    }

    /**
     * Get historical time-series data & sparklines for all targets
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
}
