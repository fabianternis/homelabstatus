<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Enum\ServiceStatus;
use App\Model\Monitor;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HttpChecker implements CheckerInterface
{
    public function __construct(
        private readonly ?HttpClientInterface $httpClient = null
    ) {}

    public function check(Monitor $monitor): CheckResultDto
    {
        $start = microtime(true);
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $monitor->target,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $monitor->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $monitor->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => false, // Homelabs frequently use self-signed / internal SSL certs
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'HomelabStatus/2.0 (Symfony; +https://github.com/fabianternis/homelabstatus)',
        ]);

        $responseBody = curl_exec($ch);
        $elapsed = (microtime(true) - $start) * 1000.0;
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlErrno !== 0 || $httpCode === 0) {
            return new CheckResultDto(
                status: ServiceStatus::OFFLINE,
                responseTimeMs: round($elapsed, 2),
                statusCode: $httpCode ?: null,
                errorMessage: $curlError ?: "Connection failed (cURL error {$curlErrno})"
            );
        }

        $expectedCode = $monitor->expectedStatusCode ?: 200;
        $matchesCode = ($httpCode === $expectedCode) ||
            ($expectedCode === 200 && $httpCode >= 200 && $httpCode < 400);

        if (!$matchesCode) {
            return new CheckResultDto(
                status: ServiceStatus::OFFLINE,
                responseTimeMs: round($elapsed, 2),
                statusCode: $httpCode,
                errorMessage: "Received HTTP {$httpCode}, expected {$expectedCode}"
            );
        }

        if (!empty($monitor->keyword)) {
            if ($responseBody === false || !str_contains((string) $responseBody, $monitor->keyword)) {
                return new CheckResultDto(
                    status: ServiceStatus::DEGRADED,
                    responseTimeMs: round($elapsed, 2),
                    statusCode: $httpCode,
                    errorMessage: "Keyword '{$monitor->keyword}' not found in response body"
                );
            }
        }

        $status = ($elapsed > 2500) ? ServiceStatus::DEGRADED : ServiceStatus::ONLINE;

        return new CheckResultDto(
            status: $status,
            responseTimeMs: round($elapsed, 2),
            statusCode: $httpCode,
            errorMessage: null
        );
    }
}
