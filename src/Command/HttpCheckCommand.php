<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CheckExecution;
use App\Enum\UplinkState;
use App\Service\Checker\CheckManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'check:http',
    aliases: ['http:check'],
    description: 'Execute HTTP / HTTPS web endpoint checks and verify SSL certificates'
)]
class HttpCheckCommand extends Command
{
    public function __construct(
        private readonly CheckManager $checkManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as pure JSON')
            ->addOption('loop', 'l', InputOption::VALUE_OPTIONAL, 'Run continuously every N seconds', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isJson = (bool)$input->getOption('json');
        $loop = (int)$input->getOption('loop');

        do {
            $executions = $this->checkManager->runChecksOfType('http');

            if ($isJson) {
                $output->writeln(json_encode(array_map(fn(CheckExecution $e) => $e->toArray(), $executions), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $io->title(sprintf('HTTP / HTTPS Endpoint Checks (%d services checked)', count($executions)));

                $rows = [];
                foreach ($executions as $exec) {
                    $rd = $exec->resultData;
                    $statusFormatted = match ($exec->status) {
                        UplinkState::EXCELLENT, UplinkState::GOOD => "<info>{$exec->status->value}</info>",
                        UplinkState::DEGRADED => "<comment>{$exec->status->value}</comment>",
                        default => "<error>{$exec->status->value}</error>",
                    };

                    $httpCode = $rd['http_code'] ?? 0;
                    $httpFormatted = ($httpCode >= 200 && $httpCode < 400)
                        ? "<info>{$httpCode}</info>"
                        : "<error>{$httpCode}</error>";

                    $ssl = '-';
                    if (isset($rd['ssl_valid'])) {
                        $days = $rd['ssl_days_remaining'] ?? 0;
                        $ssl = $rd['ssl_valid']
                            ? sprintf('<info>Valid (%d days)</info>', $days)
                            : '<error>Invalid/Expired</error>';
                    }

                    $rows[] = [
                        $exec->checkId,
                        $rd['url'] ?? '-',
                        $httpFormatted,
                        sprintf('%.1f ms', $rd['total_time_ms'] ?? 0),
                        isset($rd['ttfb_ms']) ? sprintf('%.1f ms', $rd['ttfb_ms']) : '-',
                        $ssl,
                        $statusFormatted,
                        $exec->errorMessage ?? 'OK',
                    ];
                }

                $io->table(['Check ID', 'Target URL', 'HTTP Code', 'Duration', 'TTFB', 'SSL Certificate', 'State', 'Notes'], $rows);
            }

            if ($loop > 0) {
                sleep($loop);
            }
        } while ($loop > 0);

        return Command::SUCCESS;
    }
}
