<?php

declare(strict_types=1);

namespace App\Enum;

enum UplinkState: string
{
    case EXCELLENT = 'excellent';
    case GOOD = 'good';
    case DEGRADED = 'degraded';
    case OFFLINE = 'offline';
    case UNKNOWN = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::EXCELLENT => 'Excellent',
            self::GOOD => 'Good',
            self::DEGRADED => 'Degraded',
            self::OFFLINE => 'Offline / Major Loss',
            self::UNKNOWN => 'Unknown / Pending',
        };
    }

    public function colorAnsi(): string
    {
        return match ($this) {
            self::EXCELLENT => "\033[1;32m", // Bright Green
            self::GOOD => "\033[0;32m",      // Green
            self::DEGRADED => "\033[1;33m",  // Yellow
            self::OFFLINE => "\033[1;31m",   // Bright Red
            self::UNKNOWN => "\033[0;37m",   // Gray
        };
    }

    public function colorHex(): string
    {
        return match ($this) {
            self::EXCELLENT => '#10b981',
            self::GOOD => '#34d399',
            self::DEGRADED => '#f59e0b',
            self::OFFLINE => '#ef4444',
            self::UNKNOWN => '#6b7280',
        };
    }
}
