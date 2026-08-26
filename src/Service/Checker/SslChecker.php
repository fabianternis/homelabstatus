<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Enum\ServiceStatus;
use App\Model\Monitor;

class SslChecker implements CheckerInterface
{
    public function check(Monitor $monitor): CheckResultDto
    {
        $target = $monitor->target;
        $host = parse_url($target, PHP_URL_HOST) ?: $target;
        $port = parse_url($target, PHP_URL_PORT) ?: 443;

        $start = microtime(true);
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        $client = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            (float) $monitor->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context
        );
        $elapsed = (microtime(true) - $start) * 1000.0;

        if (!$client) {
            return new CheckResultDto(
                status: ServiceStatus::OFFLINE,
                responseTimeMs: round($elapsed, 2),
                statusCode: null,
                errorMessage: "SSL handshake failed: {$errstr} ({$errno})"
            );
        }

        $params = stream_context_get_params($client);
        fclose($client);

        if (!isset($params['options']['ssl']['peer_certificate'])) {
            return new CheckResultDto(
                status: ServiceStatus::OFFLINE,
                responseTimeMs: round($elapsed, 2),
                statusCode: null,
                errorMessage: "No SSL peer certificate received from {$host}"
            );
        }

        $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        if (!$cert) {
            return new CheckResultDto(
                status: ServiceStatus::OFFLINE,
                responseTimeMs: round($elapsed, 2),
                statusCode: null,
                errorMessage: "Failed to parse SSL certificate"
            );
        }

        $validTo = (int) ($cert['validTo_time_t'] ?? 0);
        $daysRemaining = (int) floor(($validTo - time()) / 86400);

        if ($daysRemaining < 0) {
            return new CheckResultDto(
                status: ServiceStatus::OFFLINE,
                responseTimeMs: round($elapsed, 2),
                statusCode: 0,
                errorMessage: "Certificate EXPIRED " . abs($daysRemaining) . " days ago",
                metadata: ['days_remaining' => $daysRemaining]
            );
        }

        if ($daysRemaining <= 14) {
            return new CheckResultDto(
                status: ServiceStatus::DEGRADED,
                responseTimeMs: round($elapsed, 2),
                statusCode: 200,
                errorMessage: "Certificate expires soon ({$daysRemaining} days remaining)",
                metadata: ['days_remaining' => $daysRemaining]
            );
        }

        return new CheckResultDto(
            status: ServiceStatus::ONLINE,
            responseTimeMs: round($elapsed, 2),
            statusCode: 200,
            errorMessage: null,
            metadata: ['days_remaining' => $daysRemaining]
        );
    }
}
