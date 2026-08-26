<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SystemMetricsCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'homelab:metrics',
    description: 'Collect and snapshot host machine CPU, Memory, Disk, and Load metrics'
)]
class CollectMetricsCommand extends Command
{
    public function __construct(
        private readonly SystemMetricsCollector $metricsCollector
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $metric = $this->metricsCollector->collectAndSave();

        $io->title('Homelab Host Metrics Snapshot');
        $io->table(
            ['Metric', 'Value'],
            [
                ['CPU Usage', "{$metric->cpuUsagePercent}%"],
                ['Memory Used / Total', round($metric->memoryUsedBytes / (1024*1024*1024), 2) . ' GB / ' . round($metric->memoryTotalBytes / (1024*1024*1024), 2) . ' GB'],
                ['Disk Used / Total', round($metric->diskUsedBytes / (1024*1024*1024), 2) . ' GB / ' . round($metric->diskTotalBytes / (1024*1024*1024), 2) . ' GB'],
                ['System Load (1m, 5m, 15m)', "{$metric->load1m}, {$metric->load5m}, {$metric->load15m}"],
                ['Host Uptime', round($metric->uptimeSeconds / 3600, 1) . ' hours'],
            ]
        );

        return Command::SUCCESS;
    }
}
