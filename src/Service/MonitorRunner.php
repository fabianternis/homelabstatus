<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\CheckType;
use App\Enum\IncidentSeverity;
use App\Enum\ServiceStatus;
use App\Model\Incident;
use App\Model\Monitor;
use App\Repository\CheckResultRepository;
use App\Repository\IncidentRepository;
use App\Repository\MonitorRepository;
use App\Service\Checker\CheckerInterface;
use App\Service\Checker\CheckResultDto;
use App\Service\Checker\DockerChecker;
use App\Service\Checker\HttpChecker;
use App\Service\Checker\PingChecker;
use App\Service\Checker\SslChecker;
use App\Service\Checker\SystemResourceChecker;
use App\Service\Checker\TcpChecker;

class MonitorRunner
{
    private array $checkers = [];

    public function __construct(
        private readonly MonitorRepository $monitorRepository,
        private readonly CheckResultRepository $checkResultRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly NotificationService $notificationService,
        HttpChecker $httpChecker,
        TcpChecker $tcpChecker,
        PingChecker $pingChecker,
        SslChecker $sslChecker,
        DockerChecker $dockerChecker,
        SystemResourceChecker $systemChecker
    ) {
        $this->checkers = [
            CheckType::HTTP->value => $httpChecker,
            CheckType::TCP->value => $tcpChecker,
            CheckType::PING->value => $pingChecker,
            CheckType::SSL->value => $sslChecker,
            CheckType::DOCKER->value => $dockerChecker,
            CheckType::SYSTEM->value => $systemChecker,
        ];
    }

    public function getChecker(CheckType $type): CheckerInterface
    {
        return $this->checkers[$type->value] ?? $this->checkers[CheckType::HTTP->value];
    }

    public function checkMonitor(Monitor $monitor): CheckResultDto
    {
        $checker = $this->getChecker($monitor->type);
        $result = $checker->check($monitor);

        $prevStatus = $monitor->status;
        $newStatus = $result->status;

        $monitor->lastCheckedAt = gmdate('Y-m-d H:i:s');
        $monitor->lastResponseTimeMs = $result->responseTimeMs;

        if ($newStatus === ServiceStatus::OFFLINE) {
            $monitor->consecutiveFailures++;
        } else {
            $monitor->consecutiveFailures = 0;
        }

        // Status transition
        if ($prevStatus !== $newStatus) {
            $monitor->status = $newStatus;
            $monitor->lastStatusChangeAt = gmdate('Y-m-d H:i:s');

            // Handle incident lifecycle
            if ($newStatus === ServiceStatus::OFFLINE || $newStatus === ServiceStatus::DEGRADED) {
                $severity = ($newStatus === ServiceStatus::OFFLINE) ? IncidentSeverity::CRITICAL : IncidentSeverity::WARNING;
                $incident = new Incident(
                    id: null,
                    monitorId: $monitor->id,
                    title: "Outage detected on {$monitor->name}",
                    severity: $severity,
                    status: 'investigating',
                    message: $result->errorMessage ?: "Monitor reported {$newStatus->value} state.",
                    startedAt: gmdate('Y-m-d H:i:s')
                );
                $this->incidentRepository->save($incident);
            } elseif ($prevStatus === ServiceStatus::OFFLINE || $prevStatus === ServiceStatus::DEGRADED) {
                // Service recovered! Auto-resolve open incidents for this monitor
                if ($monitor->id !== null) {
                    $this->incidentRepository->resolveForMonitor($monitor->id);
                }
            }

            // Send notification
            $this->notificationService->notifyStatusChange($monitor, $prevStatus, $newStatus, $result->errorMessage);
        }

        $this->monitorRepository->save($monitor);

        // Record check history
        if ($monitor->id !== null) {
            $this->checkResultRepository->log(
                monitorId: $monitor->id,
                status: $result->status,
                responseTimeMs: $result->responseTimeMs,
                statusCode: $result->statusCode,
                errorMessage: $result->errorMessage
            );
        }

        return $result;
    }

    /**
     * Run checks for all active monitors
     * @return array<int, CheckResultDto>
     */
    public function runAll(): array
    {
        $monitors = $this->monitorRepository->findAllActive();
        $results = [];

        foreach ($monitors as $monitor) {
            $results[$monitor->id] = $this->checkMonitor($monitor);
        }

        return $results;
    }
}
