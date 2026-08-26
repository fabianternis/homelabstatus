<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\UplinkMonitorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly UplinkMonitorService $monitorService
    ) {}

    #[Route('/', name: 'home_index', methods: ['GET'])]
    public function index(): Response
    {
        $status = $this->monitorService->getCurrentStatus();
        $history = $this->monitorService->getHistoryWithSparklines(20);

        return $this->render('home/index.html.twig', [
            'status' => $status,
            'history' => $history,
        ]);
    }
}
