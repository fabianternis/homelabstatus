<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Check;
use App\Enum\UplinkState;
use App\Repository\CheckRepository;
use App\Service\Audit\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;

#[Route('/api/v1/admin/checks')]
class AdminCheckApiController extends AbstractController
{
    public function __construct(
        private readonly CheckRepository $checkRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    #[Route('', name: 'api_admin_checks_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $withTrashed = filter_var($request->query->get('with_trashed', false), FILTER_VALIDATE_BOOLEAN);
        $type = $request->query->get('type', 'uplink');
        $onlyEnabled = filter_var($request->query->get('only_enabled', false), FILTER_VALIDATE_BOOLEAN);

        $checks = $this->checkRepository->findByType($type, $onlyEnabled, $withTrashed);

        return $this->json([
            'status' => 'ok',
            'count' => count($checks),
            'data' => array_map(fn(Check $c) => $c->toArray(), $checks),
        ]);
    }

    #[Route('/{id}', name: 'api_admin_checks_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $check = $this->checkRepository->findById($id, withTrashed: true);
        if (!$check) {
            return $this->json(['status' => 'error', 'message' => 'Check not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'status' => 'ok',
            'data' => $check->toArray(),
        ]);
    }

    #[Route('', name: 'api_admin_checks_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?: $request->request->all();

        $name = trim((string)($payload['name'] ?? ''));
        $host = trim((string)($payload['host'] ?? ($payload['config']['host'] ?? '')));

        if ($name === '' || $host === '') {
            return $this->json([
                'status' => 'error',
                'message' => "Both 'name' and 'host' (or config.host) are required",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $config = $payload['config'] ?? [];
        $config['host'] = $host;
        $config['packets'] = (int)($config['packets'] ?? 2);
        $config['timeout'] = (int)($config['timeout'] ?? 2);
        $config['provider'] = (string)($config['provider'] ?? 'Custom');
        $config['country'] = (string)($config['country'] ?? 'Global');

        $interval = (int)($payload['interval'] ?? ($payload['interval_sec'] ?? 60));

        $check = new Check(
            id: Ulid::generate(),
            name: $name,
            slug: (string)($payload['slug'] ?? ''),
            type: (string)($payload['type'] ?? 'uplink'),
            groupName: (string)($payload['group_name'] ?? 'Uplink Probes'),
            description: $payload['description'] ?? null,
            isEnabled: (bool)($payload['is_enabled'] ?? true),
            status: UplinkState::UNKNOWN,
            config: $config,
            interval: max(5, $interval),
            sortOrder: (int)($payload['sort_order'] ?? 10)
        );

        $this->checkRepository->save($check);

        $this->auditLogger->log(
            event: 'created',
            description: "API created check '{$check->name}' ({$check->id})",
            subjectType: Check::class,
            subjectId: $check->id,
            properties: ['attributes' => $check->toArray()],
            logName: 'api_admin'
        );

        return $this->json([
            'status' => 'ok',
            'message' => 'Check created successfully',
            'data' => $check->toArray(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_admin_checks_update', methods: ['PUT', 'PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $check = $this->checkRepository->findById($id, withTrashed: true);
        if (!$check) {
            return $this->json(['status' => 'error', 'message' => 'Check not found'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true) ?: $request->request->all();
        $old = $check->toArray();

        if (isset($payload['name'])) $check->name = trim((string)$payload['name']);
        if (isset($payload['type'])) $check->type = trim((string)$payload['type']);
        if (isset($payload['group_name'])) $check->groupName = trim((string)$payload['group_name']);
        if (isset($payload['description'])) $check->description = $payload['description'];
        if (isset($payload['is_enabled'])) $check->isEnabled = (bool)$payload['is_enabled'];
        if (isset($payload['interval']) || isset($payload['interval_sec'])) {
            $check->interval = max(5, (int)($payload['interval'] ?? $payload['interval_sec']));
        }
        if (isset($payload['sort_order'])) $check->sortOrder = (int)$payload['sort_order'];

        if (isset($payload['config']) && is_array($payload['config'])) {
            $check->config = array_merge($check->config, $payload['config']);
        }
        if (isset($payload['host'])) {
            $check->config['host'] = trim((string)$payload['host']);
        }

        $this->checkRepository->save($check);

        $this->auditLogger->log(
            event: 'updated',
            description: "API updated check '{$check->name}' ({$check->id})",
            subjectType: Check::class,
            subjectId: $check->id,
            properties: ['old' => $old, 'attributes' => $check->toArray()],
            logName: 'api_admin'
        );

        return $this->json([
            'status' => 'ok',
            'message' => 'Check updated successfully',
            'data' => $check->toArray(),
        ]);
    }

    #[Route('/{id}', name: 'api_admin_checks_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $check = $this->checkRepository->findById($id, withTrashed: true);
        if (!$check) {
            return $this->json(['status' => 'error', 'message' => 'Check not found'], Response::HTTP_NOT_FOUND);
        }

        $this->checkRepository->softDelete($id);

        $this->auditLogger->log(
            event: 'deleted',
            description: "API soft-deleted check '{$check->name}' ({$id})",
            subjectType: Check::class,
            subjectId: $id,
            logName: 'api_admin'
        );

        return $this->json([
            'status' => 'ok',
            'message' => "Check '{$check->name}' soft-deleted successfully",
        ]);
    }

    #[Route('/{id}/restore', name: 'api_admin_checks_restore', methods: ['POST'])]
    public function restore(string $id): JsonResponse
    {
        $check = $this->checkRepository->findById($id, withTrashed: true);
        if (!$check) {
            return $this->json(['status' => 'error', 'message' => 'Check not found'], Response::HTTP_NOT_FOUND);
        }

        $this->checkRepository->restore($id);

        $this->auditLogger->log(
            event: 'restored',
            description: "API restored check '{$check->name}' ({$id})",
            subjectType: Check::class,
            subjectId: $id,
            logName: 'api_admin'
        );

        return $this->json([
            'status' => 'ok',
            'message' => "Check '{$check->name}' restored successfully",
        ]);
    }

    #[Route('/{id}/force', name: 'api_admin_checks_force_delete', methods: ['DELETE'])]
    public function forceDelete(string $id): JsonResponse
    {
        $check = $this->checkRepository->findById($id, withTrashed: true);
        if (!$check) {
            return $this->json(['status' => 'error', 'message' => 'Check not found'], Response::HTTP_NOT_FOUND);
        }

        $this->checkRepository->forceDelete($id);

        $this->auditLogger->log(
            event: 'force_deleted',
            description: "API permanently deleted check '{$check->name}' ({$id})",
            subjectType: Check::class,
            subjectId: $id,
            logName: 'api_admin'
        );

        return $this->json([
            'status' => 'ok',
            'message' => "Check '{$check->name}' permanently deleted",
        ]);
    }

    #[Route('/bulk', name: 'api_admin_checks_bulk', methods: ['POST'])]
    public function bulk(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?: $request->request->all();
        $action = (string)($payload['action'] ?? '');
        $checkIds = (array)($payload['check_ids'] ?? []);

        if (empty($checkIds) || $action === '') {
            return $this->json([
                'status' => 'error',
                'message' => "Both 'action' and 'check_ids' array are required",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $count = 0;
        foreach ($checkIds as $id) {
            $check = $this->checkRepository->findById((string)$id, withTrashed: true);
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

        $this->auditLogger->log(
            event: 'bulk_' . $action,
            description: "API bulk '{$action}' performed on {$count} checks",
            subjectType: Check::class,
            properties: ['action' => $action, 'count' => $count, 'check_ids' => $checkIds],
            logName: 'api_admin'
        );

        return $this->json([
            'status' => 'ok',
            'action' => $action,
            'affected' => $count,
            'message' => "Bulk action '{$action}' applied to {$count} checks",
        ]);
    }
}
