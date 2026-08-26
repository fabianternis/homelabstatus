<?php

declare(strict_types=1);

namespace App\Command\Admin;

use App\Repository\AuditLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'audit:list',
    description: 'List recent audit activity logs'
)]
class AuditListCommand extends Command
{
    public function __construct(
        private readonly AuditLogRepository $auditRepo
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Number of log records', 25)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int)$input->getOption('limit');
        $isJson = (bool)$input->getOption('json');

        $logs = $this->auditRepo->getRecent($limit);

        if ($isJson) {
            $output->writeln(json_encode(array_map(fn($l) => $l->toArray(), $logs), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $io->title(sprintf('Audit Activity Trail (Last %d events)', count($logs)));

        $rows = [];
        foreach ($logs as $log) {
            $eventFormatted = match ($log->event) {
                'created' => '<info>CREATED</info>',
                'updated', 'status_changed' => '<comment>' . strtoupper($log->event) . '</comment>',
                'deleted', 'force_deleted' => '<error>' . strtoupper($log->event) . '</error>',
                'restored' => '<info>RESTORED</info>',
                default => $log->event,
            };

            $rows[] = [
                $log->id,
                $eventFormatted,
                $log->description,
                $log->subjectId ?? '-',
                $log->logName,
                $log->createdAt,
            ];
        }

        $io->table(['ULID', 'Event', 'Description', 'Subject ID', 'Log Name', 'Timestamp (UTC)'], $rows);

        return Command::SUCCESS;
    }
}
