<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use Symfony\Component\Uid\Ulid;

class AuditLogger
{
    public function __construct(
        private readonly AuditLogRepository $auditRepo
    ) {}

    public function log(
        string $event,
        string $description,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?array $properties = null,
        string $logName = 'default',
        ?string $causerType = null,
        ?string $causerId = null
    ): AuditLog {
        $auditLog = new AuditLog(
            id: Ulid::generate(),
            logName: $logName,
            subjectType: $subjectType,
            subjectId: $subjectId,
            event: $event,
            causerType: $causerType,
            causerId: $causerId,
            properties: $properties,
            description: $description,
            createdAt: gmdate('Y-m-d H:i:s')
        );

        $this->auditRepo->record($auditLog);
        return $auditLog;
    }
}
