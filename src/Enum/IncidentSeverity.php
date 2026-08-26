<?php

declare(strict_types=1);

namespace App\Enum;

enum IncidentSeverity: string
{
    case INFO = 'info';
    case WARNING = 'warning';
    case CRITICAL = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::INFO => 'Informational / Maintenance',
            self::WARNING => 'Degraded Performance',
            self::CRITICAL => 'Critical Outage',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::INFO => '#3b82f6',
            self::WARNING => '#f59e0b',
            self::CRITICAL => '#ef4444',
        };
    }
}
