<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\ServiceStatus;
use App\Service\MonitorRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'homelab:check',
    description: 'Execute health checks on all active homelab monitors'
)]
class CheckMonitorsCommand extends Command
{
    public function __construct(
        private readonly MonitorRunner $monitorRunner
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('loop', 'l', InputOption::VALUE_OPTIONAL, 'Run checks in a continuous loop every N seconds', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $loopInterval = (int) $input->getOption('loop');

        do {
            $io->title('Homelab Monitor Check: ' . gmdate('Y-m-d H:i:s T'));
            $start = microtime(true);
            $results = $this->monitorRunner->runAll();
            $duration = round((microtime(true) - $start) * 1000.0, 1);

            $rows = [];
            foreach ($results as $monitorId => $res) {
                $statusTag = match ($res->status) {
                    ServiceStatus::ONLINE => '<info>ONLINE</info>',
                    ServiceStatus::DEGRADED => '<comment>DEGRADED</comment>',
                    ServiceStatus::OFFLINE => '<error>OFFLINE</error>',
                    default => '<comment>PENDING</comment>',
                };

                $rows[] = [
                    $monitorId,
                    $statusTag,
                    "{$res->responseTimeMs} ms",
                    $res->statusCode ?? '-',
                    $res->errorMessage ?? 'OK',
                ];
            }

            $io->table(['ID', 'Status', 'Latency', 'HTTP Code', 'Details'], $rows);
            $io->success(sprintf('Checked %d monitors in %s ms.', count($results), $duration));

            if ($loopInterval > 0) {
                $io->note("Sleeping for {$loopInterval} seconds... (Press Ctrl+C to stop)");
                sleep($loopInterval);
            }
        } while ($loopInterval > 0);

        return Command::SUCCESS;
    }
}
