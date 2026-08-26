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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'check:restore',
    description: 'Restore a soft-deleted check from trash'
)]
class CheckRestoreCommand extends Command
{
    public function __construct(
        private readonly CheckRepository $checkRepository,
        private readonly AuditLogger $auditLogger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Check ID (ULID) or slug');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string)$input->getArgument('id');

        $check = $this->checkRepository->findById($id, withTrashed: true) ?? $this->checkRepository->findBySlug($id, withTrashed: true);
        if (!$check) {
            $io->error("Check '{$id}' not found.");
            return Command::FAILURE;
        }

        if (!$check->isTrashed()) {
            $io->warning("Check '{$check->name}' is not in trash.");
            return Command::SUCCESS;
        }

        $this->checkRepository->restore($check->id);
        $this->auditLogger->log('restored', "Restored check '{$check->name}' ({$check->id})", Check::class, $check->id, null, 'cli');

        $io->success("Check '{$check->name}' restored successfully.");
        return Command::SUCCESS;
    }
}
