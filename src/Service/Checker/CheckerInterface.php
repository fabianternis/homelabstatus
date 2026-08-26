<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Enum\ServiceStatus;
use App\Model\Monitor;

class CheckResultDto
{
    public function __construct(
        public readonly ServiceStatus $status,
        public readonly float $responseTimeMs,
        public readonly ?int $statusCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $metadata = []
    ) {}
}

interface CheckerInterface
{
    public function check(Monitor $monitor): CheckResultDto;
}
