<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\MonitorRepository;
use App\Service\BadgeGenerator;
use App\Service\UptimeCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/badge')]
class BadgeController extends AbstractController
{
    public function __construct(
        private readonly MonitorRepository $monitorRepository,
        private readonly UptimeCalculator $uptimeCalculator,
        private readonly BadgeGenerator $badgeGenerator,
        #[Autowire(env: 'default::APP_NAME')]
        private readonly ?string $appName = 'Homelab'
    ) {}

    #[Route('/{id}/status', name: 'badge_status', methods: ['GET'])]
    public function status(string $id, Request $request): Response
    {
        $label = $request->query->get('label');

        if ($id === 'all' || $id === 'global') {
            $summary = $this->uptimeCalculator->getGlobalSystemSummary();
            $label = $label ?: ($this->appName ?: 'Homelab');
            $svg = $this->badgeGenerator->forStatus($label, $summary['status']);
            return new Response($svg, Response::HTTP_OK, [
                'Content-Type' => 'image/svg+xml; charset=utf-8',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        }

        $monitor = $this->monitorRepository->find((int) $id);
        if (!$monitor) {
            $svg = $this->badgeGenerator->generate($label ?: 'Status', 'Not Found', '#6b7280');
            return new Response($svg, Response::HTTP_NOT_FOUND, [
                'Content-Type' => 'image/svg+xml; charset=utf-8',
            ]);
        }

        $label = $label ?: $monitor->name;
        $svg = $this->badgeGenerator->forStatus($label, $monitor->status);

        return new Response($svg, Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    #[Route('/{id}/uptime', name: 'badge_uptime', methods: ['GET'])]
    public function uptime(string $id, Request $request): Response
    {
        $label = $request->query->get('label', 'uptime 30d');

        if ($id === 'all' || $id === 'global') {
            $summary = $this->uptimeCalculator->getGlobalSystemSummary();
            $svg = $this->badgeGenerator->forUptime($label, $summary['system_uptime']);
            return new Response($svg, Response::HTTP_OK, [
                'Content-Type' => 'image/svg+xml; charset=utf-8',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        }

        $monitor = $this->monitorRepository->find((int) $id);
        if (!$monitor) {
            $svg = $this->badgeGenerator->generate($label, 'N/A', '#6b7280');
            return new Response($svg, Response::HTTP_NOT_FOUND, [
                'Content-Type' => 'image/svg+xml; charset=utf-8',
            ]);
        }

        $days = (int) $request->query->get('days', 30);
        $uptimes = $this->uptimeCalculator->calculateUptime($monitor->id);
        $uptimeVal = match ($days) {
            1 => $uptimes['24h'],
            7 => $uptimes['7d'],
            90 => $uptimes['90d'],
            default => $uptimes['30d'],
        };

        $svg = $this->badgeGenerator->forUptime($label, $uptimeVal);

        return new Response($svg, Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
