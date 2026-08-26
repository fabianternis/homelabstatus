<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CheckResultRepository;
use App\Repository\IncidentRepository;
use App\Repository\MonitorRepository;
use App\Repository\SystemMetricRepository;
use App\Service\SystemMetricsCollector;
use App\Service\UptimeCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly MonitorRepository $monitorRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly CheckResultRepository $checkResultRepository,
        private readonly SystemMetricRepository $systemMetricRepository,
        private readonly SystemMetricsCollector $systemMetricsCollector,
        private readonly UptimeCalculator $uptimeCalculator,
        #[Autowire(env: 'default::APP_NAME')]
        private readonly ?string $appName = 'Homelab Status',
        #[Autowire(env: 'default::ENABLE_HOST_METRICS')]
        private readonly ?string $enableHostMetrics = 'true'
    ) {}

    #[Route('/', name: 'dashboard_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $groupedMonitors = $this->monitorRepository->findAllGrouped(includePrivate: false);
        $summary = $this->uptimeCalculator->getGlobalSystemSummary();
        $activeIncidents = $this->incidentRepository->findActive();
        $recentIncidents = $this->incidentRepository->findRecent(5);

        $systemMetrics = null;
        if ($this->enableHostMetrics === 'true' || $this->enableHostMetrics === '1') {
            $systemMetrics = $this->systemMetricRepository->findLatest() ?? $this->systemMetricsCollector->collectAndSave();
        }

        $uptimeData = [];
        $historyData = [];
        foreach ($groupedMonitors as $group => $monitors) {
            foreach ($monitors as $monitor) {
                $uptimeData[$monitor->id] = $this->uptimeCalculator->calculateUptime($monitor->id);
                $historyData[$monitor->id] = $this->uptimeCalculator->getDailyHistory($monitor->id, 30);
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'appName' => $this->appName ?: 'Homelab Status',
            'groupedMonitors' => $groupedMonitors,
            'summary' => $summary,
            'activeIncidents' => $activeIncidents,
            'recentIncidents' => $recentIncidents,
            'systemMetrics' => $systemMetrics,
            'uptimeData' => $uptimeData,
            'historyData' => $historyData,
            'lastUpdated' => gmdate('Y-m-d H:i:s T'),
        ]);
    }

    #[Route('/monitors/{id}', name: 'dashboard_show', methods: ['GET'])]
    public function show(int $id, Request $request): Response
    {
        $monitor = $this->monitorRepository->find($id);
        if (!$monitor || ($monitor->isPrivate && !$this->isAuthenticated($request))) {
            throw $this->createNotFoundException("Monitor #{$id} does not exist or is private.");
        }

        $uptimes = $this->uptimeCalculator->calculateUptime($monitor->id);
        $dailyHistory = $this->uptimeCalculator->getDailyHistory($monitor->id, 60);
        $recentChecks = $this->checkResultRepository->getRecentForMonitor($monitor->id, 50);

        return $this->render('dashboard/show.html.twig', [
            'appName' => $this->appName ?: 'Homelab Status',
            'monitor' => $monitor,
            'uptimes' => $uptimes,
            'dailyHistory' => $dailyHistory,
            'recentChecks' => $recentChecks,
        ]);
    }

    private function isAuthenticated(Request $request): bool
    {
        $adminPassword = $_ENV['ADMIN_PASSWORD'] ?? '';
        $apiKey = $_ENV['API_KEY'] ?? '';

        $authHeader = $request->headers->get('Authorization', '');
        if ($apiKey && ($authHeader === "Bearer {$apiKey}" || $request->query->get('api_key') === $apiKey)) {
            return true;
        }

        $cookie = $request->cookies->get('homelab_auth', '');
        return !empty($adminPassword) && hash_equals(sha1($adminPassword), $cookie);
    }
}
