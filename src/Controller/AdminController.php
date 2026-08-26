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
            $country = trim((string)$request->request->get('country', 'Global'));
            $packets = max(1, min(10, (int)$request->request->get('packets', 2)));
            $timeout = max(1, min(10, (int)$request->request->get('timeout', 2)));
            $sortOrder = (int)$request->request->get('sort_order', 10);
            $isEnabled = (bool)$request->request->get('is_enabled', true);

            if ($name !== '' && $host !== '') {
                $check = new Check(
                    id: Ulid::generate(),
                    name: $name,
                    slug: '',
                    type: $type,
                    groupName: $groupName,
                    description: $description,
                    isEnabled: $isEnabled,
                    status: UplinkState::UNKNOWN,
                    config: [
                        'host' => $host,
                        'packets' => $packets,
                        'timeout' => $timeout,
                        'provider' => $provider,
                        'country' => $country,
                    ],
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
            $check->sortOrder = (int)$request->request->get('sort_order', $check->sortOrder);
            $check->isEnabled = (bool)$request->request->get('is_enabled');

            $config = $check->config;
            $config['host'] = trim((string)$request->request->get('host', $config['host'] ?? '127.0.0.1'));
            $config['provider'] = trim((string)$request->request->get('provider', $config['provider'] ?? ''));
            $config['country'] = trim((string)$request->request->get('country', $config['country'] ?? 'Global'));
            $config['packets'] = max(1, min(10, (int)$request->request->get('packets', 2)));
            $config['timeout'] = max(1, min(10, (int)$request->request->get('timeout', 2)));
            $check->config = $config;

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
}
