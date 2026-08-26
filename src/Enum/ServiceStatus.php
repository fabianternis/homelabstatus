<?php

declare(strict_types=1);

namespace App\Enum;

enum ServiceStatus: string
{
    case ONLINE = 'online';
    case DEGRADED = 'degraded';
    case OFFLINE = 'offline';
    case MAINTENANCE = 'maintenance';
    case PENDING = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::ONLINE => 'Operational',
            self::DEGRADED => 'Degraded Performance',
            self::OFFLINE => 'Major Outage',
            self::MAINTENANCE => 'Under Maintenance',
            self::PENDING => 'Pending Check',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ONLINE => '#10b981',      // Emerald
            self::DEGRADED => '#f59e0b',    // Amber
            self::OFFLINE => '#ef4444',     // Red
            self::MAINTENANCE => '#3b82f6',  // Blue
            self::PENDING => '#6b7280',      // Gray
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::ONLINE => 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30',
            self::DEGRADED => 'bg-amber-500/15 text-amber-400 border-amber-500/30',
            self::OFFLINE => 'bg-rose-500/15 text-rose-400 border-rose-500/30',
            self::MAINTENANCE => 'bg-blue-500/15 text-blue-400 border-blue-500/30',
            self::PENDING => 'bg-zinc-500/15 text-zinc-400 border-zinc-500/30',
        };
    }
}
