<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\UplinkState;
use App\Service\UplinkMonitorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'uplink:ping',
    description: 'Execute ICMP ping probes against external upstream targets'
)]
class PingCommand extends Command
{
    public function __construct(
        private readonly UplinkMonitorService $monitorService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Number of ICMP packets per target', 3)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output pure JSON format (for scripting / pipelines)')
            ->addOption('loop', 'l', InputOption::VALUE_OPTIONAL, 'Run continuously every N seconds', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = (int)$input->getOption('count');
        $isJson = (bool)$input->getOption('json');
        $loopInterval = (int)$input->getOption('loop');

        do {
            $summary = $this->monitorService->probeAll($count);

            if ($isJson) {
                $output->writeln(json_encode($summary->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $stateColor = match ($summary->state) {
                    UplinkState::EXCELLENT, UplinkState::GOOD => 'info',
                    UplinkState::DEGRADED => 'comment',
                    default => 'error',
                };

                $io->title(sprintf(
                    'Uplink Status: <%s>%s</%s> (Health Score: %d/100) — %s',
                    $stateColor,
                    strtoupper($summary->state->value),
                    $stateColor,
                    $summary->healthScore,
                    $summary->evaluatedAt->format('H:i:s T')
                ));

                $rows = [];
                foreach ($summary->targets as $res) {
                    $statusFormatted = match ($res->state) {
                        UplinkState::EXCELLENT => '<info>EXCELLENT</info>',
                        UplinkState::GOOD => '<info>GOOD</info>',
                        UplinkState::DEGRADED => '<comment>DEGRADED</comment>',
                        default => '<error>OFFLINE</error>',
                    };

                    $rows[] = [
                        $res->targetId,
                        $res->host,
                        $statusFormatted,
                        $res->avgLatencyMs !== null ? sprintf('%.1f ms', $res->avgLatencyMs) : '-',
                        $res->minLatencyMs !== null ? sprintf('%.1f ms', $res->minLatencyMs) : '-',
                        $res->maxLatencyMs !== null ? sprintf('%.1f ms', $res->maxLatencyMs) : '-',
                        $res->jitterMs !== null ? sprintf('%.1f ms', $res->jitterMs) : '-',
                        sprintf('%.0f%%', $res->packetLossPercent),
                        $res->errorMessage ?? 'OK',
                    ];
                }

                $io->table(
                    ['Target ID', 'Host', 'State', 'Avg RTT', 'Min RTT', 'Max RTT', 'Jitter', 'Loss', 'Notes'],
                    $rows
                );

                $io->text(sprintf(
                    'Summary: Avg Latency: %.2f ms | Loss: %.1f%% | Jitter: %.2f ms | Healthy Targets: %d/%d',
                    $summary->avgLatencyMs,
                    $summary->avgPacketLossPercent,
                    $summary->avgJitterMs,
                    $summary->healthyTargetsCount,
                    $summary->activeTargetsCount
                ));
            }

            if ($loopInterval > 0) {
                sleep($loopInterval);
            }
        } while ($loopInterval > 0);

        return Command::SUCCESS;
    }
}
