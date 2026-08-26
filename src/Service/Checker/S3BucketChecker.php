<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Entity\Check;
use App\Entity\CheckExecution;
use App\Enum\UplinkState;
use Symfony\Component\Uid\Ulid;

class S3BucketChecker implements CheckerInterface
{
    public function supports(string $type): bool
    {
        return in_array($type, ['s3', 'bucket', 'object_storage'], true);
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
            $provider = strtolower((string)($config['provider'] ?? 'aws'));
            $bucket = (string)($config['bucket'] ?? '');
            $region = (string)($config['region'] ?? 'us-east-1');
            $endpoint = (string)($config['endpoint'] ?? '');
            $accessKey = (string)($config['access_key'] ?? '');
            $secretKey = (string)($config['secret_key'] ?? '');
            $objectKey = (string)($config['object_key'] ?? '');
            $checkPublicAccess = (bool)($config['check_public_access'] ?? false);
            $timeout = max(1, min(60, (int)($config['timeout'] ?? 10)));

            $targetUrl = $this->resolveEndpointUrl($provider, $bucket, $region, $endpoint, $objectKey);
            $parsedUrl = parse_url($targetUrl);
            $host = $parsedUrl['host'] ?? '';
            $path = $parsedUrl['path'] ?? '/';

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $targetUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; HomelabStatus/1.0)',
            ]);

            $headers = [];
            if (!$checkPublicAccess && $accessKey !== '' && $secretKey !== '') {
                $amzdate = gmdate('Ymd\THis\Z');
                $datestamp = gmdate('Ymd');
                $payloadHash = hash('sha256', '');
                
                $headers[] = 'x-amz-content-sha256: ' . $payloadHash;
                $headers[] = 'x-amz-date: ' . $amzdate;
                $headers[] = 'host: ' . $host;
                
                $canonicalUri = $this->encodeUri($path);
                $canonicalQuerystring = '';
                $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzdate}\n";
                $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
                
                $canonicalRequest = "HEAD\n{$canonicalUri}\n{$canonicalQuerystring}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
                
                $algorithm = 'AWS4-HMAC-SHA256';
                $service = 's3';
                $credentialScope = "{$datestamp}/{$region}/{$service}/aws4_request";
                $stringToSign = "{$algorithm}\n{$amzdate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
                
                $kSecret = 'AWS4' . $secretKey;
                $kDate = hash_hmac('sha256', $datestamp, $kSecret, true);
                $kRegion = hash_hmac('sha256', $region, $kDate, true);
                $kService = hash_hmac('sha256', $service, $kRegion, true);
                $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
                
                $signature = hash_hmac('sha256', $stringToSign, $kSigning);
                
                $authorizationHeader = "{$algorithm} Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
                $headers[] = 'Authorization: ' . $authorizationHeader;
            }

            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            curl_multi_add_handle($mh, $ch);
            $handles[$check->id] = [
                'check' => $check,
                'handle' => $ch,
                'target_url' => $targetUrl,
                'provider' => $provider,
                'bucket' => $bucket,
                'region' => $region,
                'object_key' => $objectKey,
            ];
        }

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

        $executions = [];
        foreach ($handles as $checkId => $data) {
            /** @var Check $check */
            $check = $data['check'];
            $ch = $data['handle'];
            
            $info = curl_getinfo($ch);
            $error = curl_error($ch);
            $errno = curl_errno($ch);

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $executions[$checkId] = $this->evaluateResult(
                $check,
                $data['target_url'],
                $data['provider'],
                $data['bucket'],
                $data['region'],
                $data['object_key'],
                $info,
                $error,
                $errno,
                $now
            );
        }

        curl_multi_close($mh);
        return $executions;
    }

    public function resolveEndpointUrl(string $provider, string $bucket, string $region, string $endpoint, string $objectKey): string
    {
        $path = $objectKey !== '' ? '/' . ltrim($objectKey, '/') : '/';

        if ($provider === 'aws') {
            return "https://{$bucket}.s3.{$region}.amazonaws.com{$path}";
        }
        
        if ($provider === 'hetzner') {
            return "https://{$region}.your-objectstorage.com/{$bucket}{$path}";
        }
        
        $baseEndpoint = rtrim($endpoint, '/');
        if ($provider === 'r2') {
            return "{$baseEndpoint}/{$bucket}{$path}";
        }
        
        return "{$baseEndpoint}/{$bucket}{$path}";
    }

    private function encodeUri(string $uri): string
    {
        $parts = explode('/', $uri);
        $encodedParts = array_map('rawurlencode', $parts);
        return implode('/', $encodedParts);
    }

    private function evaluateResult(
        Check $check,
        string $url,
        string $provider,
        string $bucket,
        string $region,
        string $objectKey,
        array $info,
        string $curlError,
        int $curlErrno,
        string $timestamp
    ): CheckExecution {
        $httpCode = (int)($info['http_code'] ?? 0);
        $totalTimeMs = round((float)($info['total_time'] ?? 0) * 1000, 2);

        $state = UplinkState::OFFLINE;
        $errorMessage = null;

        if ($curlErrno !== 0 || $httpCode === 0) {
            $state = UplinkState::OFFLINE;
            $errorMessage = $curlError ?: 'Connection failed or timed out';
        } elseif ($httpCode === 404) {
            $state = UplinkState::OFFLINE;
            $errorMessage = 'Bucket or object not found (HTTP 404)';
        } elseif ($httpCode === 403) {
            $state = UplinkState::DEGRADED;
            $errorMessage = 'Access denied (HTTP 403) - Bucket reachable but authorization failed';
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            if ($totalTimeMs <= 400.0) {
                $state = UplinkState::EXCELLENT;
            } elseif ($totalTimeMs <= 1000.0) {
                $state = UplinkState::GOOD;
            } elseif ($totalTimeMs <= 2500.0) {
                $state = UplinkState::DEGRADED;
            } else {
                $state = UplinkState::OFFLINE;
                $errorMessage = sprintf('Response time too slow: %.0f ms', $totalTimeMs);
            }
        } else {
            $state = UplinkState::OFFLINE;
            $errorMessage = sprintf('Unexpected HTTP status: %d', $httpCode);
        }

        $resultData = [
            'provider' => $provider,
            'bucket' => $bucket,
            'endpoint_used' => $url,
            'region' => $region,
            'http_code' => $httpCode,
            'duration_ms' => $totalTimeMs,
            'error' => $errorMessage,
        ];

        if ($objectKey !== '') {
            $resultData['object_key'] = $objectKey;
        }

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
