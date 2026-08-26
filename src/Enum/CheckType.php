<?php

declare(strict_types=1);

namespace App\Enum;

enum CheckType: string
{
    case HTTP = 'http';
    case TCP = 'tcp';
    case PING = 'ping';
    case DOCKER = 'docker';
    case SSL = 'ssl';
    case SYSTEM = 'system';

    public function label(): string
    {
        return match ($this) {
            self::HTTP => 'HTTP / HTTPS',
            self::TCP => 'TCP Port',
            self::PING => 'ICMP Ping',
            self::DOCKER => 'Docker Container',
            self::SSL => 'SSL Certificate',
            self::SYSTEM => 'System Resources',
        };
    }
}
