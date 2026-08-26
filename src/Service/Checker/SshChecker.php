<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Entity\Check;
use App\Entity\CheckExecution;
use App\Enum\UplinkState;
use Symfony\Component\Uid\Ulid;

class SshChecker implements CheckerInterface
{
    public function supports(string $type): bool
    {
        return in_array($type, ['ssh', 'sftp'], true);
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

        $executions = [];
        $now = gmdate('Y-m-d H:i:s');

        foreach ($checks as $check) {
            $executions[$check->id] = $this->executeSingle($check, $now);
        }

        return $executions;
    }

    private function executeSingle(Check $check, string $timestamp): CheckExecution
    {
        $config = $check->config;
        $host = (string)($config['host'] ?? '');
        $port = (int)($config['port'] ?? 22);
        $timeout = (int)($config['timeout'] ?? 5);
        $checkBanner = (bool)($config['check_banner'] ?? true);
        $expectedBannerContains = $config['expected_banner_contains'] ?? null;

        $tcpReachable = false;
        $bannerRaw = null;
        $sshVersionString = null;
        $softwareVersion = null;
        $protocolVersion = null;
        $bannerMatch = null;

        $state = UplinkState::UNKNOWN;
        $errorMessage = null;

        $start = microtime(true);
        $fp = @fsockopen($host, $port, $errno, $errstr, (float)$timeout);
        $durationMs = (microtime(true) - $start) * 1000.0;

        if ($fp !== false) {
            $tcpReachable = true;

            if ($checkBanner) {
                stream_set_timeout($fp, max(1, $timeout));
                $banner = fgets($fp, 512);

                if (is_string($banner) && $banner !== '') {
                    $bannerRaw = trim($banner);
                    
                    $parsed = $this->parseBanner($bannerRaw);
                    $sshVersionString = $parsed['ssh_version_string'];
                    $protocolVersion = $parsed['protocol_version'];
                    $softwareVersion = $parsed['software_version'];
                }
            }

            fclose($fp);
        } else {
            $errorMessage = $errstr ?: 'Connection failed or timed out';
        }

        if (!$tcpReachable) {
            $state = UplinkState::OFFLINE;
        } elseif ($checkBanner && $protocolVersion === null) {
            $state = UplinkState::DEGRADED;
            $errorMessage = 'No banner or invalid SSH banner received';
        } else {
            if ($checkBanner && is_string($expectedBannerContains) && $expectedBannerContains !== '') {
                $bannerMatch = str_contains((string)$bannerRaw, $expectedBannerContains);
                if (!$bannerMatch) {
                    $state = UplinkState::DEGRADED;
                    $errorMessage = sprintf("Banner did not contain expected string '%s'", $expectedBannerContains);
                }
            }

            if ($state !== UplinkState::DEGRADED) {
                if ($durationMs <= 50.0) {
                    $state = UplinkState::EXCELLENT;
                } elseif ($durationMs <= 300.0) {
                    $state = UplinkState::GOOD;
                } else {
                    $state = UplinkState::DEGRADED;
                    $errorMessage = sprintf('Slow response time: %.0f ms', $durationMs);
                }
            }
        }

        $resultData = [
            'host' => $host,
            'port' => $port,
            'duration_ms' => round($durationMs, 2),
            'tcp_reachable' => $tcpReachable,
            'banner_raw' => $bannerRaw,
            'ssh_version_string' => $sshVersionString,
            'software_version' => $softwareVersion,
            'protocol_version' => $protocolVersion,
            'banner_match' => $bannerMatch,
        ];

        return new CheckExecution(
            id: Ulid::generate(),
            checkId: $check->id,
            status: $state,
            durationMs: $durationMs,
            resultData: $resultData,
            errorMessage: $errorMessage,
            executedAt: $timestamp
        );
    }

    /**
     * @return array{ssh_version_string: ?string, protocol_version: ?string, software_version: ?string}
     */
    private function parseBanner(string $bannerRaw): array
    {
        $sshVersionString = null;
        $protocolVersion = null;
        $softwareVersion = null;

        if (preg_match('/^SSH-([0-9\.]+)-([^ \r\n]+)/', $bannerRaw, $matches)) {
            $sshVersionString = $matches[0];
            $protocolVersion = $matches[1];
            $softwareVersion = $matches[2];
        }

        return [
            'ssh_version_string' => $sshVersionString,
            'protocol_version' => $protocolVersion,
            'software_version' => $softwareVersion,
        ];
    }
}
