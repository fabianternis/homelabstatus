<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\UplinkMonitorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly UplinkMonitorService $monitorService
    ) {}

    #[Route('/', name: 'home_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = $this->monitorService->getCurrentStatus();
        $history = $this->monitorService->getHistoryWithSparklines(20);

        // Resolve client / gateway IP
        $clientIp = $request->headers->get('CF-Connecting-IP')
            ?: $request->headers->get('X-Forwarded-For')
            ?: $request->getClientIp()
            ?: '127.0.0.1';

        // Extract first IP if multiple present in X-Forwarded-For
        if (str_contains($clientIp, ',')) {
            $clientIp = trim(explode(',', $clientIp)[0]);
        }

        return $this->render('home/index.html.twig', [
            'status' => $status,
            'history' => $history,
            'clientIp' => $clientIp,
        ]);
    }
}
