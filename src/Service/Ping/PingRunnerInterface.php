<?php

declare(strict_types=1);

namespace App\Service\Ping;

use App\DTO\PingResultDto;
use App\DTO\TargetDto;

interface PingRunnerInterface
{
    public function ping(TargetDto $target, int $count = 3, int $timeoutSeconds = 2): PingResultDto;
}
