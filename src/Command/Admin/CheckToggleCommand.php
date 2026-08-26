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
    name: 'check:toggle',
    description: 'Toggle (enable/disable) a monitored check'
)]
class CheckToggleCommand extends Command
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

        $check->isEnabled = !$check->isEnabled;
        $this->checkRepository->save($check);

        $statusStr = $check->isEnabled ? 'ENABLED' : 'DISABLED';

        $this->auditLogger->log(
            event: 'updated',
            description: "Check '{$check->name}' was {$statusStr}",
            subjectType: Check::class,
            subjectId: $check->id,
            properties: ['is_enabled' => $check->isEnabled],
            logName: 'cli'
        );

        $io->success("Check '{$check->name}' is now {$statusStr}.");
        return Command::SUCCESS;
    }
}
