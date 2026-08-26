<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Entity\Check;
use App\Entity\CheckExecution;

interface CheckerInterface
{
    /**
     * Whether this checker handles the given check type
     */
    public function supports(string $type): bool;

    /**
     * Executes a single check
     */
    public function run(Check $check): CheckExecution;

    /**
     * Executes multiple checks in parallel
     *
     * @param Check[] $checks
     * @return array<string, CheckExecution> Keyed by check ID
     */
    public function runMulti(array $checks): array;
}
