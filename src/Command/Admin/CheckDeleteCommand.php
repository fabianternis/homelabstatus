<?php

declare(strict_types=1);

namespace App\Command\Admin;

use App\Entity\Check;
use App\Repository\CheckRepository;
use App\Service\Audit\AuditLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'check:delete',
    description: 'Soft-delete (or force permanently delete) a check'
)]
class CheckDeleteCommand extends Command
{
    public function __construct(
        private readonly CheckRepository $checkRepository,
        private readonly AuditLogger $auditLogger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Check ID (ULID) or slug')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Permanently delete check from database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string)$input->getArgument('id');
        $force = (bool)$input->getOption('force');

        $check = $this->checkRepository->findById($id, withTrashed: true) ?? $this->checkRepository->findBySlug($id, withTrashed: true);
        if (!$check) {
            $io->error("Check '{$id}' not found.");
            return Command::FAILURE;
        }

        if ($force) {
            $this->checkRepository->forceDelete($check->id);
            $this->auditLogger->log('force_deleted', "Permanently deleted check '{$check->name}' ({$check->id})", Check::class, $check->id, null, 'cli');
            $io->success("Check '{$check->name}' permanently deleted.");
        } else {
            $this->checkRepository->softDelete($check->id);
            $this->auditLogger->log('deleted', "Soft-deleted check '{$check->name}' ({$check->id})", Check::class, $check->id, null, 'cli');
            $io->success("Check '{$check->name}' soft-deleted (moved to trash).");
        }

        return Command::SUCCESS;
    }
}
