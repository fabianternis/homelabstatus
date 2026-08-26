<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\CheckType;
use App\Enum\IncidentSeverity;
use App\Model\Incident;
use App\Model\Monitor;
use App\Repository\IncidentRepository;
use App\Repository\MonitorRepository;
use App\Service\MonitorRunner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly MonitorRepository $monitorRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly MonitorRunner $monitorRunner,
        #[Autowire(env: 'default::APP_NAME')]
        private readonly ?string $appName = 'Homelab Status',
        #[Autowire(env: 'default::ADMIN_PASSWORD')]
        private readonly ?string $adminPassword = null
    ) {}

    private function isAuthenticated(Request $request): bool
    {
        if (empty($this->adminPassword)) {
            return true;
        }

        $cookie = $request->cookies->get('homelab_auth', '');
        return hash_equals(sha1($this->adminPassword), $cookie);
    }

    #[Route('/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($this->isAuthenticated($request)) {
            return $this->redirectToRoute('admin_index');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');
            if (!empty($this->adminPassword) && hash_equals($this->adminPassword, $password)) {
                $response = $this->redirectToRoute('admin_index');
                $response->headers->setCookie(
                    Cookie::create('homelab_auth')
                        ->withValue(sha1($this->adminPassword))
                        ->withExpires(time() + (86400 * 30))
                        ->withPath('/')
                        ->withHttpOnly(true)
                        ->withSameSite(Cookie::SAMESITE_LAX)
                );
                return $response;
            }
            $error = 'Invalid admin password.';
        }

        return $this->render('admin/login.html.twig', [
            'appName' => $this->appName ?: 'Homelab Status',
            'error' => $error,
        ]);
    }

    #[Route('/logout', name: 'admin_logout', methods: ['GET', 'POST'])]
    public function logout(): Response
    {
        $response = $this->redirectToRoute('dashboard_index');
        $response->headers->clearCookie('homelab_auth');
        return $response;
    }

    #[Route('', name: 'admin_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAuthenticated($request)) {
            return $this->redirectToRoute('admin_login');
        }

        $monitors = $this->monitorRepository->findAll();
        $incidents = $this->incidentRepository->findActive();

        return $this->render('admin/index.html.twig', [
            'appName' => $this->appName ?: 'Homelab Status',
            'monitors' => $monitors,
            'incidents' => $incidents,
            'checkTypes' => CheckType::cases(),
        ]);
    }

    #[Route('/monitors', name: 'admin_store_monitor', methods: ['POST'])]
    public function storeMonitor(Request $request): Response
    {
        if (!$this->isAuthenticated($request)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $name = trim((string) $request->request->get('name'));
        $target = trim((string) $request->request->get('target'));
        $type = CheckType::tryFrom((string) $request->request->get('type')) ?? CheckType::HTTP;
        $group = trim((string) $request->request->get('group_name')) ?: 'Services';
        $description = trim((string) $request->request->get('description')) ?: null;
        $timeout = (int) $request->request->get('timeout_seconds', 5);
        $expectedStatus = (int) $request->request->get('expected_status_code', 200);
        $keyword = trim((string) $request->request->get('keyword')) ?: null;
        $isPrivate = (bool) $request->request->get('is_private', false);

        if (empty($name) || empty($target)) {
            $this->addFlash('error', 'Name and target are required');
            return $this->redirectToRoute('admin_index');
        }

        $monitor = new Monitor(
            id: null,
            name: $name,
            groupName: $group,
            description: $description,
            type: $type,
            target: $target,
            expectedStatusCode: $expectedStatus,
            keyword: $keyword,
            timeoutSeconds: $timeout,
            isPrivate: $isPrivate
        );
        $this->monitorRepository->save($monitor);

        // Immediate check
        $this->monitorRunner->checkMonitor($monitor);

        $this->addFlash('success', "Monitor '{$name}' created successfully!");
        return $this->redirectToRoute('admin_index');
    }

    #[Route('/monitors/{id}/delete', name: 'admin_delete_monitor', methods: ['POST'])]
    public function deleteMonitor(int $id, Request $request): Response
    {
        if (!$this->isAuthenticated($request)) {
            return $this->redirectToRoute('admin_login');
        }

        $this->monitorRepository->delete($id);
        $this->addFlash('success', 'Monitor deleted successfully.');
        return $this->redirectToRoute('admin_index');
    }

    #[Route('/monitors/{id}/check', name: 'admin_check_monitor', methods: ['POST'])]
    public function triggerCheck(int $id, Request $request): JsonResponse
    {
        if (!$this->isAuthenticated($request)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $monitor = $this->monitorRepository->find($id);
        if (!$monitor) {
            return $this->json(['error' => 'Monitor not found'], Response::HTTP_NOT_FOUND);
        }

        $res = $this->monitorRunner->checkMonitor($monitor);

        return $this->json([
            'status' => 'ok',
            'result' => [
                'status' => $res->status->value,
                'status_label' => $res->status->label(),
                'response_time_ms' => $res->responseTimeMs,
                'status_code' => $res->statusCode,
                'error' => $res->errorMessage,
            ]
        ]);
    }

    #[Route('/incidents', name: 'admin_store_incident', methods: ['POST'])]
    public function createIncident(Request $request): Response
    {
        if (!$this->isAuthenticated($request)) {
            return $this->redirectToRoute('admin_login');
        }

        $title = trim((string) $request->request->get('title'));
        $message = trim((string) $request->request->get('message'));
        $severity = IncidentSeverity::tryFrom((string) $request->request->get('severity')) ?? IncidentSeverity::WARNING;
        $monitorId = $request->request->get('monitor_id') ? (int) $request->request->get('monitor_id') : null;

        if (!empty($title) && !empty($message)) {
            $incident = new Incident(
                id: null,
                monitorId: $monitorId,
                title: $title,
                severity: $severity,
                status: 'investigating',
                message: $message,
                startedAt: gmdate('Y-m-d H:i:s')
            );
            $this->incidentRepository->save($incident);
            $this->addFlash('success', 'Incident posted successfully.');
        }

        return $this->redirectToRoute('admin_index');
    }

    #[Route('/incidents/{id}/resolve', name: 'admin_resolve_incident', methods: ['POST'])]
    public function resolveIncident(int $id, Request $request): Response
    {
        if (!$this->isAuthenticated($request)) {
            return $this->redirectToRoute('admin_login');
        }

        $this->incidentRepository->resolve($id);
        $this->addFlash('success', 'Incident resolved.');
        return $this->redirectToRoute('admin_index');
    }
}
