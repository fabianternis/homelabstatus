<?php

declare(strict_types=1);

namespace App\Command\Admin;

use App\Entity\Check;
use App\Enum\UplinkState;
use App\Repository\CheckRepository;
use App\Service\Audit\AuditLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Ulid;

#[AsCommand(
    name: 'check:create',
    description: 'Create a new monitored check in the database'
)]
class CheckCreateCommand extends Command
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
            ->addArgument('name', InputArgument::REQUIRED, 'Name of the check')
            ->addArgument('host', InputArgument::REQUIRED, 'Target host/IP to probe')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Check type', 'uplink')
            ->addOption('group', 'g', InputOption::VALUE_OPTIONAL, 'Group name', 'Uplink Probes')
            ->addOption('provider', 'p', InputOption::VALUE_OPTIONAL, 'Provider name', 'Custom')
            ->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'Probe interval in seconds', 60)
            ->addOption('packets', null, InputOption::VALUE_OPTIONAL, 'ICMP packets per check', 2)
            ->addOption('timeout', null, InputOption::VALUE_OPTIONAL, 'Timeout in seconds', 2);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string)$input->getArgument('name');
        $host = (string)$input->getArgument('host');
        $type = (string)$input->getOption('type');
        $group = (string)$input->getOption('group');
        $provider = (string)$input->getOption('provider');
        $interval = max(5, (int)$input->getOption('interval'));
        $packets = (int)$input->getOption('packets');
        $timeout = (int)$input->getOption('timeout');

        $check = new Check(
            id: Ulid::generate(),
            name: $name,
            slug: '',
            type: $type,
            groupName: $group,
            description: "Target: {$host}",
            isEnabled: true,
            status: UplinkState::UNKNOWN,
            config: [
                'host' => $host,
                'packets' => $packets,
                'timeout' => $timeout,
                'provider' => $provider,
                'country' => 'Global',
            ],
            interval: $interval,
            sortOrder: 10
        );

        $this->checkRepository->save($check);

        $this->auditLogger->log(
            event: 'created',
            description: "CLI created check '{$check->name}' ({$check->id})",
            subjectType: Check::class,
            subjectId: $check->id,
            properties: ['attributes' => $check->toArray()],
            logName: 'cli'
        );

        $io->success(sprintf("Check '%s' created with ULID %s", $check->name, $check->id));
        return Command::SUCCESS;
    }
}
