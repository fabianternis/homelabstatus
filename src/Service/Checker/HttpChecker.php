<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Entity\Check;
use App\Entity\CheckExecution;
use App\Enum\UplinkState;
use Symfony\Component\Uid\Ulid;

class HttpChecker implements CheckerInterface
{
    public function supports(string $type): bool
    {
        return in_array($type, ['http', 'https', 'web'], true);
    }

    public function run(Check $check): CheckExecution
    {
        $results = $this->runMulti([$check]);
        return $results[$check->id];
    }

    /**
     * @param Check[] $checks
     * @return array<string, CheckExecution>
     */
    public function runMulti(array $checks): array
    {
        if (empty($checks)) {
            return [];
        }

        $mh = curl_multi_init();
        $handles = [];
        $now = gmdate('Y-m-d H:i:s');

        foreach ($checks as $check) {
            $config = $check->config;
            $rawTarget = $config['url'] ?? ($config['host'] ?? 'localhost');
            if (str_starts_with($rawTarget, 'http://') || str_starts_with($rawTarget, 'https://')) {
                $url = $rawTarget;
            } else {
                $url = 'https://' . $rawTarget;
            }

            $timeout = max(1, min(30, (int)($config['timeout'] ?? 5)));
            $method = strtoupper((string)($config['method'] ?? 'GET'));
            $headers = $config['headers'] ?? [];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; HomelabStatus/1.0; +https://github.com/fabianternis/homelabstatus)',
                CURLOPT_SSL_VERIFYPEER => (bool)($config['check_ssl'] ?? true),
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CERTINFO => true,
            ]);

            if ($method === 'HEAD') {
                curl_setopt($ch, CURLOPT_NOBODY, true);
            } elseif ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if (isset($config['body'])) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($config['body']) ? json_encode($config['body']) : $config['body']);
                }
            }

            if (!empty($headers)) {
                $formattedHeaders = [];
                foreach ($headers as $k => $v) {
                    $formattedHeaders[] = is_numeric($k) ? $v : "{$k}: {$v}";
                }
                curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
            }

            curl_multi_add_handle($mh, $ch);
            $handles[$check->id] = [
                'check' => $check,
                'handle' => $ch,
                'url' => $url,
            ];
        }

        // Execute all curl requests asynchronously in parallel
        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc === CURLM_OK) {
            if (curl_multi_select($mh) === -1) {
                usleep(5000);
            }
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc === CURLM_CALL_MULTI_PERFORM);
        }

        // Collect results
        $executions = [];
        foreach ($handles as $checkId => $data) {
            /** @var Check $check */
            $check = $data['check'];
            $ch = $data['handle'];
            $url = $data['url'];

            $rawResponse = curl_multi_getcontent($ch);
            $info = curl_getinfo($ch);
            $error = curl_error($ch);
            $errno = curl_errno($ch);

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $execution = $this->evaluateResult($check, $url, $info, $rawResponse, $error, $errno, $now);
            $executions[$checkId] = $execution;
        }

        curl_multi_close($mh);
        return $executions;
    }

    private function evaluateResult(
        Check $check,
        string $url,
        array $info,
        ?string $response,
        string $curlError,
        int $curlErrno,
        string $timestamp
    ): CheckExecution {
        $config = $check->config;
        $expectedStatus = (int)($config['expected_status'] ?? 200);
        $keyword = $config['keyword'] ?? null;

        $httpCode = (int)($info['http_code'] ?? 0);
        $totalTimeMs = round((float)($info['total_time'] ?? 0) * 1000, 2);
        $namelookupTimeMs = round((float)($info['namelookup_time'] ?? 0) * 1000, 2);
        $connectTimeMs = round((float)($info['connect_time'] ?? 0) * 1000, 2);
        $appconnectTimeMs = round((float)($info['appconnect_time'] ?? 0) * 1000, 2);
        $ttfbMs = round((float)($info['starttransfer_time'] ?? 0) * 1000, 2);

        // SSL Certificate Inspection
        $sslValid = null;
        $sslDaysRemaining = null;
        $sslIssuer = null;
        $sslExpiresAt = null;

        if (str_starts_with($url, 'https://')) {
            $certInfo = $info['certinfo'] ?? [];
            if (!empty($certInfo) && isset($certInfo[0]['Expire date'])) {
                try {
                    $expireDate = new \DateTimeImmutable($certInfo[0]['Expire date']);
                    $sslExpiresAt = $expireDate->format('Y-m-d H:i:s');
                    $diff = (new \DateTimeImmutable())->diff($expireDate);
                    $sslDaysRemaining = $diff->invert === 1 ? -$diff->days : $diff->days;
                    $sslValid = $sslDaysRemaining > 0;
                    $sslIssuer = $certInfo[0]['Issuer'] ?? null;
                } catch (\Throwable) {
                    $sslValid = true;
                }
            } elseif ($httpCode > 0) {
                $sslValid = true;
            }
        }

        // Keyword verification
        $keywordFound = null;
        if ($keyword !== null && $keyword !== '' && $response !== null) {
            $keywordFound = str_contains($response, (string)$keyword);
        }

        // Status classification
        $state = UplinkState::OFFLINE;
        $errorMessage = null;

        if ($curlErrno !== 0 || $httpCode === 0) {
            $state = UplinkState::OFFLINE;
            $errorMessage = $curlError ?: 'Connection failed or timed out';
        } elseif ($expectedStatus > 0 && $httpCode !== $expectedStatus) {
            $state = UplinkState::OFFLINE;
            $errorMessage = sprintf('Expected HTTP %d, got HTTP %d', $expectedStatus, $httpCode);
        } elseif ($keywordFound === false) {
            $state = UplinkState::DEGRADED;
            $errorMessage = "Keyword '{$keyword}' not found in response body";
        } elseif ($sslDaysRemaining !== null && $sslDaysRemaining <= 14) {
            $state = UplinkState::DEGRADED;
            $errorMessage = sprintf('SSL certificate expiring in %d days', $sslDaysRemaining);
        } elseif ($totalTimeMs > 2500.0) {
            $state = UplinkState::DEGRADED;
            $errorMessage = sprintf('Slow response time: %.0f ms', $totalTimeMs);
        } elseif ($totalTimeMs <= 800.0) {
            $state = UplinkState::EXCELLENT;
        } else {
            $state = UplinkState::GOOD;
        }

        $resultData = [
            'url' => $url,
            'http_code' => $httpCode,
            'total_time_ms' => $totalTimeMs,
            'ttfb_ms' => $ttfbMs,
            'connect_time_ms' => $connectTimeMs,
            'namelookup_time_ms' => $namelookupTimeMs,
            'ssl_handshake_time_ms' => $appconnectTimeMs,
            'ssl_valid' => $sslValid,
            'ssl_days_remaining' => $sslDaysRemaining,
            'ssl_issuer' => $sslIssuer,
            'ssl_expires_at' => $sslExpiresAt,
            'keyword_found' => $keywordFound,
            'primary_ip' => $info['primary_ip'] ?? null,
            'redirect_count' => (int)($info['redirect_count'] ?? 0),
        ];

        return new CheckExecution(
            id: Ulid::generate(),
            checkId: $check->id,
            status: $state,
            durationMs: $totalTimeMs,
            resultData: $resultData,
            errorMessage: $errorMessage,
            executedAt: $timestamp
        );
    }
}
