<?php

declare(strict_types=1);

namespace App\Command\Admin;

use App\Entity\Check;
use App\Repository\CheckRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'check:list',
    description: 'List all configured checks in the database'
)]
class CheckListCommand extends Command
{
    public function __construct(
        private readonly CheckRepository $checkRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Filter by check type (e.g. uplink)', 'uplink')
            ->addOption('trashed', null, InputOption::VALUE_NONE, 'Include soft-deleted checks')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as raw JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = (string)$input->getOption('type');
        $trashed = (bool)$input->getOption('trashed');
        $isJson = (bool)$input->getOption('json');

        $checks = $this->checkRepository->findByType($type, onlyEnabled: false, withTrashed: $trashed);

        if ($isJson) {
            $output->writeln(json_encode(array_map(fn(Check $c) => $c->toArray(), $checks), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $io->title(sprintf('Database Checks (Type: %s, Total: %d)', $type, count($checks)));

        $rows = [];
        foreach ($checks as $check) {
            $statusFormatted = match ($check->status->value) {
                'excellent', 'good' => "<info>{$check->status->value}</info>",
                'degraded' => "<comment>{$check->status->value}</comment>",
                'offline' => "<error>{$check->status->value}</error>",
                default => "<fg=gray>{$check->status->value}</>",
            };

            $stateBadge = $check->isTrashed()
                ? '<error>TRASHED</error>'
                : ($check->isEnabled ? '<info>ENABLED</info>' : '<comment>DISABLED</comment>');

            $host = $check->config['url'] ?? ($check->config['host'] ?? '-');
            $lat = isset($check->lastMetrics['avg_latency_ms'])
                ? sprintf('%.1f ms', $check->lastMetrics['avg_latency_ms'])
                : (isset($check->lastMetrics['duration_ms']) ? sprintf('%.1f ms', $check->lastMetrics['duration_ms']) : '-');

            $rows[] = [
                $check->id,
                $check->name,
                $host,
                $check->interval . 's',
                $stateBadge,
                $statusFormatted,
                $lat,
                $check->groupName,
            ];
        }

        $io->table(['ULID', 'Name', 'Target Host', 'Interval', 'State', 'Health', 'Last Latency', 'Group'], $rows);

        return Command::SUCCESS;
    }
}
