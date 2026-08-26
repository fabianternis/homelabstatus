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

#[AsCommand(
    name: 'uplink:tui',
    aliases: ['uplink:monitor'],
    description: 'Launch interactive live Terminal User Interface (TUI) for real-time uplink monitoring'
)]
class TuiMonitorCommand extends Command
{
    public function __construct(
        private readonly UplinkMonitorService $monitorService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'Refresh interval in seconds', 3)
            ->addOption('packets', 'p', InputOption::VALUE_OPTIONAL, 'Packets per probe', 2);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = max(1, (int)$input->getOption('interval'));
        $packets = max(1, (int)$input->getOption('packets'));

        // Hide cursor and clear screen
        $output->write("\033[?25l\033[2J");

        // Trap signals if pcntl is available
        if (function_exists('pcntl_signal')) {
            $restore = function () use ($output) {
                $output->write("\033[?25h\033[0m\n");
                exit(0);
            };
            pcntl_signal(SIGINT, $restore);
            pcntl_signal(SIGTERM, $restore);
        }

        try {
            while (true) {
                // Trigger probe run
                $summary = $this->monitorService->probeAll($packets);
                $history = $this->monitorService->getHistoryWithSparklines(24);

                $this->renderDashboard($output, $summary, $history, $interval);
                sleep($interval);
            }
        } finally {
            // Restore cursor
            $output->write("\033[?25h\033[0m\n");
        }

        return Command::SUCCESS;
    }

    private function renderDashboard(OutputInterface $output, \App\DTO\UplinkSummaryDto $summary, array $history, int $interval): void
    {
        // Move cursor to top-left
        $output->write("\033[H");

        $stateColor = match ($summary->state) {
            UplinkState::EXCELLENT => "\033[1;32m", // Bright green
            UplinkState::GOOD => "\033[0;32m",      // Green
            UplinkState::DEGRADED => "\033[1;33m",  // Yellow
            default => "\033[1;31m",                // Red
        };
        $reset = "\033[0m";
        $dim = "\033[2m";
        $bold = "\033[1m";
        $cyan = "\033[1;36m";

        $timeStr = $summary->evaluatedAt->format('Y-m-d H:i:s T');

        // Render Top Header Box
        $lines = [];
        $lines[] = "{$cyan}┌──────────────────────────────────────────────────────────────────────────────────┐{$reset}";
        $lines[] = sprintf(
            "{$cyan}│{$reset} {$bold}HOMELAB UPLINK STATUS MONITOR{$reset} %32s %s {$cyan}│{$reset}",
            "Updated: " . $timeStr,
            ""
        );
        $lines[] = "{$cyan}├──────────────────────────────────────────────────────────────────────────────────┤{$reset}";

        // Score Bar
        $barWidth = 25;
        $filled = (int)round(($summary->healthScore / 100.0) * $barWidth);
        $empty = max(0, $barWidth - $filled);
        $healthBar = str_repeat('█', $filled) . str_repeat('░', $empty);

        $lines[] = sprintf(
            "{$cyan}│{$reset} State: %s%-12s%s │ Score: [%s%s%s] %3d/100 │ Targets: %d/%d Healthy  {$cyan}│{$reset}",
            $stateColor,
            strtoupper($summary->state->value),
            $reset,
            $stateColor,
            $healthBar,
            $reset,
            $summary->healthScore,
            $summary->healthyTargetsCount,
            $summary->activeTargetsCount
        );

        $lines[] = sprintf(
            "{$cyan}│{$reset} Avg Latency: {$bold}%6.1f ms{$reset} │ Avg Loss: {$bold}%4.1f%%{$reset} │ Avg Jitter: {$bold}%5.2f ms{$reset} %14s {$cyan}│{$reset}",
            $summary->avgLatencyMs,
            $summary->avgPacketLossPercent,
            $summary->avgJitterMs,
            ""
        );
        $lines[] = "{$cyan}└──────────────────────────────────────────────────────────────────────────────────┘{$reset}";
        $lines[] = "";

        // Targets Table
        $lines[] = " {$bold}TARGET PROBES & LATENCY SPARKLINE (Last 24 samples):{$reset}";
        $lines[] = " ┌──────────────────────────┬──────────────┬──────────┬──────────┬──────────┬─────────────┬──────────┐";
        $lines[] = " │ Target                   │ Host         │ Latency  │ Min/Max  │ Jitter   │ History     │ Status   │";
        $lines[] = " ├──────────────────────────┼──────────────┼──────────┼──────────┼──────────┼─────────────┼──────────┤";

        foreach ($summary->targets as $targetRes) {
            $tData = $history['targets'][$targetRes->targetId] ?? null;
            $sparkline = $tData['sparkline'] ?? str_repeat('─', 11);

            $badge = match ($targetRes->state) {
                UplinkState::EXCELLENT => "\033[1;32m● EXCELLENT\033[0m",
                UplinkState::GOOD => "\033[0;32m● GOOD     \033[0m",
                UplinkState::DEGRADED => "\033[1;33m▲ DEGRADED \033[0m",
                default => "\033[1;31m✖ OFFLINE  \033[0m",
            };

            $latStr = $targetRes->avgLatencyMs !== null ? sprintf('%5.1f ms', $targetRes->avgLatencyMs) : '  DOWN  ';
            $minMaxStr = ($targetRes->minLatencyMs !== null && $targetRes->maxLatencyMs !== null)
                ? sprintf('%3.0f/%-3.0f', $targetRes->minLatencyMs, $targetRes->maxLatencyMs)
                : '   -   ';
            $jitStr = $targetRes->jitterMs !== null ? sprintf('±%4.1f ms', $targetRes->jitterMs) : '   -    ';

            $name = mb_strimwidth($tData['target']['name'] ?? $targetRes->targetId, 0, 24, '..');

            $lines[] = sprintf(
                " │ %-24s │ %-12s │ %8s │ %8s │ %8s │ %-11s │ %-8s │",
                $name,
                $targetRes->host,
                $latStr,
                $minMaxStr,
                $jitStr,
                $sparkline,
                $badge
            );
        }

        $lines[] = " └──────────────────────────┴──────────────┴──────────┴──────────┴──────────┴─────────────┴──────────┘";
        $lines[] = "";
        $lines[] = " {$dim}Auto-refreshing every {$interval}s • Press Ctrl+C to exit • API: http://localhost:8080/api/v1/uplink{$reset}";

        $output->writeln(implode("\n", $lines));
    }
}
