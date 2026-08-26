<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\CheckExecution;
use App\Repository\CheckExecutionRepository;
use App\Repository\CheckRepository;
use App\Service\Checker\CheckManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/http')]
class HttpApiController extends AbstractController
{
    public function __construct(
        private readonly CheckRepository $checkRepository,
        private readonly CheckExecutionRepository $executionRepository,
        private readonly CheckManager $checkManager
    ) {}

    #[Route('', name: 'api_http_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $checks = $this->checkRepository->findByType('http', onlyEnabled: true);
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
            'type' => 'http',
            'count' => count($data),
            'data' => $data,
        ]);
    }

    #[Route('/check', name: 'api_http_check_now', methods: ['POST'])]
    public function checkNow(): JsonResponse
    {
        $executions = $this->checkManager->runChecksOfType('http');

        return $this->json([
            'status' => 'ok',
            'message' => sprintf('%d HTTP endpoints checked successfully', count($executions)),
            'data' => array_map(fn(CheckExecution $e) => $e->toArray(), $executions),
        ]);
    }
}
