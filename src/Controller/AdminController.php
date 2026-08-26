<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Check;
use App\Enum\UplinkState;
use App\Repository\AuditLogRepository;
use App\Repository\CheckExecutionRepository;
use App\Repository\CheckRepository;
use App\Service\Audit\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;

#[Route('/admin')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly CheckRepository $checkRepository,
        private readonly CheckExecutionRepository $executionRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $allChecks = $this->checkRepository->findByType('uplink', onlyEnabled: false, withTrashed: true);
        $activeChecks = array_filter($allChecks, fn(Check $c) => $c->isEnabled && !$c->isTrashed());
        $trashedChecks = array_filter($allChecks, fn(Check $c) => $c->isTrashed());
        $degradedChecks = array_filter($activeChecks, fn(Check $c) => $c->status === UplinkState::DEGRADED || $c->status === UplinkState::OFFLINE);

        $totalExecutions = $this->executionRepository->getTotalCount();
        $aggregates = $this->executionRepository->getRollingAggregates(60);
        $recentAuditLogs = $this->auditLogRepository->getRecent(10);

        return $this->render('admin/index.html.twig', [
            'totalChecks' => count($allChecks),
            'activeChecksCount' => count($activeChecks),
            'trashedChecksCount' => count($trashedChecks),
            'degradedChecksCount' => count($degradedChecks),
            'totalExecutions' => $totalExecutions,
            'aggregates' => $aggregates,
            'recentAuditLogs' => $recentAuditLogs,
            'checks' => $allChecks,
        ]);
    }

    #[Route('/checks', name: 'admin_checks_index', methods: ['GET'])]
    public function checks(Request $request): Response
    {
        $filter = $request->query->get('filter', 'all'); // all, active, disabled, trashed
        $allChecks = $this->checkRepository->findByType('uplink', onlyEnabled: false, withTrashed: true);

        $checks = match ($filter) {
            'active' => array_filter($allChecks, fn(Check $c) => $c->isEnabled && !$c->isTrashed()),
            'disabled' => array_filter($allChecks, fn(Check $c) => !$c->isEnabled && !$c->isTrashed()),
            'trashed' => array_filter($allChecks, fn(Check $c) => $c->isTrashed()),
            default => array_filter($allChecks, fn(Check $c) => !$c->isTrashed()),
        };

        return $this->render('admin/checks.html.twig', [
            'checks' => $checks,
            'filter' => $filter,
            'totalActive' => count(array_filter($allChecks, fn(Check $c) => $c->isEnabled && !$c->isTrashed())),
            'totalTrashed' => count(array_filter($allChecks, fn(Check $c) => $c->isTrashed())),
        ]);
    }

    #[Route('/checks/create', name: 'admin_checks_create', methods: ['GET', 'POST'])]
    public function createCheck(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $name = trim((string)$request->request->get('name'));
            $host = trim((string)$request->request->get('host'));
            $type = trim((string)$request->request->get('type', 'uplink'));
            $groupName = trim((string)$request->request->get('group_name', 'Uplink Probes'));
            $description = trim((string)$request->request->get('description', ''));
            $provider = trim((string)$request->request->get('provider', 'Custom'));
            $timeout = max(1, min(60, (int)$request->request->get('timeout', 5)));
            $interval = max(5, (int)$request->request->get('interval', 60));
            $sortOrder = (int)$request->request->get('sort_order', 10);
            $isEnabled = (bool)$request->request->get('is_enabled', true);

            if ($name !== '') {
                $config = $this->buildCheckConfig($type, $host, $provider, $timeout, $request);

                $check = new Check(
                    id: Ulid::generate(),
                    name: $name,
                    slug: '',
                    type: $type,
                    groupName: $groupName,
                    description: $description,
                    isEnabled: $isEnabled,
                    status: UplinkState::UNKNOWN,
                    config: $config,
                    interval: $interval,
                    sortOrder: $sortOrder
                );

                $this->checkRepository->save($check);

                $this->auditLogger->log(
                    event: 'created',
                    description: "Created new check '{$check->name}' ({$check->id})",
                    subjectType: Check::class,
                    subjectId: $check->id,
                    properties: ['attributes' => $check->toArray()],
                    logName: 'admin'
                );

                $this->addFlash('success', "Check '{$check->name}' created successfully!");
                return $this->redirectToRoute('admin_checks_index');
            }

            $this->addFlash('error', 'Name and Target Host are required.');
        }

        return $this->render('admin/check_form.html.twig', [
            'check' => null,
            'isEdit' => false,
        ]);
    }

    #[Route('/checks/{id}/edit', name: 'admin_checks_edit', methods: ['GET', 'POST'])]
    public function editCheck(string $id, Request $request): Response
    {
        $check = $this->checkRepository->findById($id, withTrashed: true);
        if (!$check) {
            throw $this->createNotFoundException("Check '{$id}' not found");
        }

        if ($request->isMethod('POST')) {
            $oldAttributes = $check->toArray();

            $check->name = trim((string)$request->request->get('name', $check->name));
            $check->type = trim((string)$request->request->get('type', $check->type));
            $check->groupName = trim((string)$request->request->get('group_name', $check->groupName));
            $check->description = trim((string)$request->request->get('description', ''));
            $check->interval = max(5, (int)$request->request->get('interval', $check->interval));
            $check->sortOrder = (int)$request->request->get('sort_order', $check->sortOrder);
            $check->isEnabled = (bool)$request->request->get('is_enabled');

            $host = trim((string)$request->request->get('host', $check->config['host'] ?? $check->config['url'] ?? $check->config['server'] ?? ''));
            $provider = trim((string)$request->request->get('provider', $check->config['provider'] ?? ''));
            $timeout = max(1, min(60, (int)$request->request->get('timeout', $check->config['timeout'] ?? 5)));

            $check->config = $this->buildCheckConfig($check->type, $host, $provider, $timeout, $request, $check->config);

            $this->checkRepository->save($check);

            $this->auditLogger->log(
                event: 'updated',
                description: "Updated check '{$check->name}' ({$check->id})",
                subjectType: Check::class,
                subjectId: $check->id,
                properties: [
                    'old' => $oldAttributes,
                    'attributes' => $check->toArray(),
                ],
                logName: 'admin'
            );

            $this->addFlash('success', "Check '{$check->name}' updated successfully!");
            return $this->redirectToRoute('admin_checks_index');
        }

        return $this->render('admin/check_form.html.twig', [
            'check' => $check,
            'isEdit' => true,
        ]);
    }

    #[Route('/checks/{id}/toggle', name: 'admin_checks_toggle', methods: ['POST'])]
    public function toggleCheck(string $id): Response
    {
        $check = $this->checkRepository->findById($id, withTrashed: true);
        if (!$check) {
            throw $this->createNotFoundException("Check '{$id}' not found");
        }

        $check->isEnabled = !$check->isEnabled;
        $this->checkRepository->save($check);

        $statusText = $check->isEnabled ? 'enabled' : 'disabled';
        $this->auditLogger->log(
            event: 'updated',
            description: "Check '{$check->name}' was {$statusText}",
            subjectType: Check::class,
            subjectId: $check->id,
            properties: ['is_enabled' => $check->isEnabled],
            logName: 'admin'
        );

        $this->addFlash('success', "Check '{$check->name}' has been {$statusText}.");
        return $this->redirectToRoute('admin_checks_index');
    }

    #[Route('/checks/{id}/delete', name: 'admin_checks_delete', methods: ['POST'])]
    public function deleteCheck(string $id): Response
    {
        $check = $this->checkRepository->findById($id, withTrashed: true);
        if ($check) {
            $this->checkRepository->softDelete($id);

            $this->auditLogger->log(
                event: 'deleted',
                description: "Soft deleted check '{$check->name}' ({$id})",
                subjectType: Check::class,
                subjectId: $id,
                logName: 'admin'
            );

            $this->addFlash('success', "Check '{$check->name}' moved to trash (soft-deleted).");
        }

        return $this->redirectToRoute('admin_checks_index');
    }

    #[Route('/checks/{id}/restore', name: 'admin_checks_restore', methods: ['POST'])]
    public function restoreCheck(string $id): Response
    {
        $check = $this->checkRepository->findById($id, withTrashed: true);
        if ($check) {
            $this->checkRepository->restore($id);

            $this->auditLogger->log(
                event: 'restored',
                description: "Restored soft-deleted check '{$check->name}' ({$id})",
                subjectType: Check::class,
                subjectId: $id,
                logName: 'admin'
            );

            $this->addFlash('success', "Check '{$check->name}' restored successfully.");
        }

        return $this->redirectToRoute('admin_checks_index', ['filter' => 'trashed']);
    }

    #[Route('/checks/{id}/force-delete', name: 'admin_checks_force_delete', methods: ['POST'])]
    public function forceDeleteCheck(string $id): Response
    {
        $check = $this->checkRepository->findById($id, withTrashed: true);
        if ($check) {
            $this->checkRepository->forceDelete($id);

            $this->auditLogger->log(
                event: 'force_deleted',
                description: "Permanently deleted check '{$check->name}' ({$id})",
                subjectType: Check::class,
                subjectId: $id,
                logName: 'admin'
            );

            $this->addFlash('success', "Check '{$check->name}' permanently deleted.");
        }

        return $this->redirectToRoute('admin_checks_index', ['filter' => 'trashed']);
    }

    #[Route('/checks/bulk-action', name: 'admin_checks_bulk_action', methods: ['POST'])]
    public function bulkAction(Request $request): Response
    {
        $action = trim((string)$request->request->get('action'));
        $checkIds = (array)$request->request->all('check_ids');
        $filter = (string)$request->request->get('filter', 'all');

        if (empty($checkIds)) {
            $this->addFlash('error', 'No checks were selected.');
            return $this->redirectToRoute('admin_checks_index', ['filter' => $filter]);
        }

        $count = 0;
        foreach ($checkIds as $id) {
            $id = (string)$id;
            $check = $this->checkRepository->findById($id, withTrashed: true);
            if (!$check) continue;

            match ($action) {
                'enable' => (function() use ($check, &$count) {
                    if (!$check->isEnabled) {
                        $check->isEnabled = true;
                        $this->checkRepository->save($check);
                        $count++;
                    }
                })(),
                'disable' => (function() use ($check, &$count) {
                    if ($check->isEnabled) {
                        $check->isEnabled = false;
                        $this->checkRepository->save($check);
                        $count++;
                    }
                })(),
                'trash' => (function() use ($check, &$count) {
                    if (!$check->isTrashed()) {
                        $this->checkRepository->softDelete($check->id);
                        $count++;
                    }
                })(),
                'restore' => (function() use ($check, &$count) {
                    if ($check->isTrashed()) {
                        $this->checkRepository->restore($check->id);
                        $count++;
                    }
                })(),
                'force_delete' => (function() use ($check, &$count) {
                    $this->checkRepository->forceDelete($check->id);
                    $count++;
                })(),
                default => null,
            };
        }

        if ($count > 0) {
            $this->auditLogger->log(
                event: 'bulk_' . $action,
                description: "Executed bulk '{$action}' on {$count} checks",
                subjectType: Check::class,
                properties: ['action' => $action, 'count' => $count, 'check_ids' => $checkIds],
                logName: 'admin'
            );

            $this->addFlash('success', "Bulk operation '{$action}' completed on {$count} checks.");
        } else {
            $this->addFlash('info', 'No checks were affected by the operation.');
        }

        return $this->redirectToRoute('admin_checks_index', ['filter' => $filter]);
    }

    #[Route('/executions', name: 'admin_executions_index', methods: ['GET'])]
    public function executions(Request $request): Response
    {
        $page = max(1, (int)$request->query->get('page', 1));
        $checkId = $request->query->get('check_id') ?: null;

        $pagination = $this->executionRepository->paginate($checkId, $page, 50);
        $checks = $this->checkRepository->findByType('uplink', onlyEnabled: false, withTrashed: true);

        return $this->render('admin/executions.html.twig', [
            'pagination' => $pagination,
            'checks' => $checks,
            'selectedCheckId' => $checkId,
            'page' => $page,
        ]);
    }

    #[Route('/audit-logs', name: 'admin_audit_logs_index', methods: ['GET'])]
    public function auditLogs(Request $request): Response
    {
        $limit = max(10, min(200, (int)$request->query->get('limit', 50)));
        $logs = $this->auditLogRepository->getRecent($limit);

        return $this->render('admin/audit_logs.html.twig', [
            'logs' => $logs,
            'limit' => $limit,
        ]);
    }

    /**
     * Builds a type-specific config array from the submitted form request.
     * $existing is used during edit to preserve fields not present in the form.
     */
    private function buildCheckConfig(
        string $type,
        string $host,
        string $provider,
        int $timeout,
        Request $request,
        array $existing = []
    ): array {
        $packets = max(1, min(10, (int)$request->request->get('packets', $existing['packets'] ?? 2)));
        $country = trim((string)$request->request->get('country', $existing['country'] ?? 'Global'));

        // HTTP / HTTPS / Web
        if (in_array($type, ['http', 'https', 'web'], true)) {
            return [
                'url' => $host,
                'host' => $host,
                'method' => strtoupper(trim((string)$request->request->get('method', $existing['method'] ?? 'GET'))),
                'expected_status' => (int)$request->request->get('expected_status', $existing['expected_status'] ?? 200),
                'keyword' => trim((string)$request->request->get('keyword', $existing['keyword'] ?? '')),
                'check_ssl' => (bool)$request->request->get('check_ssl', $existing['check_ssl'] ?? true),
                'timeout' => $timeout,
                'provider' => $provider,
            ];
        }

        // S3 / Object Storage
        if (in_array($type, ['s3', 'bucket', 'object_storage'], true)) {
            return [
                'provider' => strtolower(trim((string)$request->request->get('s3_provider', $existing['s3_provider'] ?? 'aws'))),
                'bucket' => trim((string)$request->request->get('s3_bucket', $existing['bucket'] ?? '')),
                'region' => trim((string)$request->request->get('s3_region', $existing['region'] ?? 'us-east-1')),
                'endpoint' => trim((string)$request->request->get('s3_endpoint', $existing['endpoint'] ?? '')),
                'access_key' => trim((string)$request->request->get('s3_access_key', $existing['access_key'] ?? '')),
                'secret_key' => trim((string)$request->request->get('s3_secret_key', $existing['secret_key'] ?? '')),
                'object_key' => trim((string)$request->request->get('s3_object_key', $existing['object_key'] ?? '')),
                'check_public_access' => (bool)$request->request->get('s3_check_public_access', $existing['check_public_access'] ?? false),
                'timeout' => $timeout,
                's3_provider' => strtolower(trim((string)$request->request->get('s3_provider', $existing['s3_provider'] ?? 'aws'))),
            ];
        }

        // Database (MySQL, MariaDB, Postgres, Redis, SQLite)
        if (in_array($type, ['database', 'db', 'mysql', 'postgres', 'mariadb', 'redis', 'sqlite'], true)) {
            $dbPort = $request->request->get('db_port', '');
            return [
                'driver' => strtolower(trim((string)$request->request->get('db_driver', $existing['driver'] ?? 'mysql'))),
                'host' => $host,
                'port' => $dbPort !== '' ? (int)$dbPort : ($existing['port'] ?? null),
                'database' => trim((string)$request->request->get('db_database', $existing['database'] ?? '')),
                'username' => trim((string)$request->request->get('db_username', $existing['username'] ?? '')),
                'password' => trim((string)$request->request->get('db_password', $existing['password'] ?? '')),
                'query' => trim((string)$request->request->get('db_query', $existing['query'] ?? '')),
                'expected_result' => trim((string)$request->request->get('db_expected_result', $existing['expected_result'] ?? '')),
                'timeout' => $timeout,
                'provider' => $provider,
            ];
        }

        // SSH / SFTP
        if (in_array($type, ['ssh', 'sftp'], true)) {
            return [
                'host' => $host,
                'port' => (int)$request->request->get('ssh_port', $existing['port'] ?? 22),
                'timeout' => $timeout,
                'check_banner' => (bool)$request->request->get('ssh_check_banner', $existing['check_banner'] ?? true),
                'expected_banner_contains' => trim((string)$request->request->get('ssh_expected_banner', $existing['expected_banner_contains'] ?? '')),
                'provider' => $provider,
            ];
        }

        // DNS
        if (in_array($type, ['dns', 'dns_server', 'resolver'], true)) {
            return [
                'server' => $host,
                'port' => (int)$request->request->get('dns_port', $existing['port'] ?? 53),
                'protocol' => strtolower(trim((string)$request->request->get('dns_protocol', $existing['protocol'] ?? 'udp'))),
                'query_name' => trim((string)$request->request->get('dns_query_name', $existing['query_name'] ?? 'cloudflare.com')),
                'query_type' => strtoupper(trim((string)$request->request->get('dns_query_type', $existing['query_type'] ?? 'A'))),
                'expected_answer' => trim((string)$request->request->get('dns_expected_answer', $existing['expected_answer'] ?? '')),
                'timeout' => $timeout,
                'provider' => $provider,
            ];
        }

        // DHCP
        if ($type === 'dhcp') {
            return [
                'server' => $host,
                'port' => (int)$request->request->get('dhcp_port', $existing['port'] ?? 67),
                'timeout' => $timeout,
                'client_mac' => trim((string)$request->request->get('dhcp_client_mac', $existing['client_mac'] ?? '')),
                'expected_server_id' => trim((string)$request->request->get('dhcp_expected_server_id', $existing['expected_server_id'] ?? '')),
                'provider' => $provider,
            ];
        }

        // Default / uplink / icmp_ping
        return [
            'host' => $host,
            'packets' => $packets,
            'timeout' => $timeout,
            'provider' => $provider,
            'country' => $country,
        ];
    }
}
