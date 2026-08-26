<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\CheckType;
use App\Enum\IncidentSeverity;
use App\Model\Incident;
use App\Model\Monitor;
use App\Repository\IncidentRepository;
use App\Repository\MonitorRepository;
use App\Service\MonitorRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'homelab:seed',
    description: 'Populate sample homelab services and sample incident for demonstration'
)]
class SeedMonitorsCommand extends Command
{
    public function __construct(
        private readonly MonitorRepository $monitorRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly MonitorRunner $monitorRunner
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Seeding Homelab Monitored Services');

        $sampleServices = [
            [
                'name' => 'Gateway DNS (1.1.1.1)',
                'group' => 'Core Network',
                'description' => 'Primary DNS resolver connectivity',
                'type' => CheckType::PING,
                'target' => '1.1.1.1',
                'timeout' => 3,
            ],
            [
                'name' => 'Google DNS (8.8.8.8)',
                'group' => 'Core Network',
                'description' => 'Secondary DNS resolver connectivity',
                'type' => CheckType::PING,
                'target' => '8.8.8.8',
                'timeout' => 3,
            ],
            [
                'name' => 'Local Host Disk Space',
                'group' => 'Infrastructure',
                'description' => 'Storage volume utilization alert check',
                'type' => CheckType::SYSTEM,
                'target' => 'disk',
                'timeout' => 5,
            ],
            [
                'name' => 'Local Host CPU & Memory',
                'group' => 'Infrastructure',
                'description' => 'Host CPU load & RAM consumption check',
                'type' => CheckType::SYSTEM,
                'target' => 'cpu',
                'timeout' => 5,
            ],
            [
                'name' => 'GitHub API Health',
                'group' => 'External Dependencies',
                'description' => 'GitHub REST API status endpoint',
                'type' => CheckType::HTTP,
                'target' => 'https://api.github.com/zen',
                'timeout' => 5,
            ],
            [
                'name' => 'Public Web Gateway (Cloudflare)',
                'group' => 'External Dependencies',
                'description' => 'HTTPS reachability & SSL certificate verification',
                'type' => CheckType::HTTP,
                'target' => 'https://cloudflare.com',
                'timeout' => 5,
            ],
        ];

        foreach ($sampleServices as $idx => $srv) {
            $monitor = new Monitor(
                id: null,
                name: $srv['name'],
                groupName: $srv['group'],
                description: $srv['description'],
                type: $srv['type'],
                target: $srv['target'],
                timeoutSeconds: $srv['timeout'],
                sortOrder: $idx * 10
            );
            $this->monitorRepository->save($monitor);
            $this->monitorRunner->checkMonitor($monitor);
            $io->text("✔ Created & checked: {$srv['name']}");
        }

        // Add a sample resolved maintenance incident
        $incident = new Incident(
            id: null,
            monitorId: null,
            title: 'Scheduled Router Firmware Upgrade',
            severity: IncidentSeverity::INFO,
            status: 'resolved',
            message: 'Main router firmware upgraded successfully. All services resumed normal operations.',
            startedAt: gmdate('Y-m-d H:i:s', strtotime('-1 day')),
            resolvedAt: gmdate('Y-m-d H:i:s', strtotime('-23 hours'))
        );
        $this->incidentRepository->save($incident);

        $io->success('Sample monitors and incidents seeded successfully!');
        return Command::SUCCESS;
    }
}
