<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Entity\Check;
use App\Entity\CheckExecution;
use App\Enum\UplinkState;
use PDO;
use PDOException;
use Symfony\Component\Uid\Ulid;
use Throwable;

class DatabaseChecker implements CheckerInterface
{
    public function supports(string $type): bool
    {
        return in_array($type, ['database', 'db', 'mysql', 'postgres', 'redis', 'mariadb'], true);
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
        $executions = [];
        foreach ($checks as $check) {
            $executions[$check->id] = $this->executeSingle($check);
        }
        return $executions;
    }

    private function executeSingle(Check $check): CheckExecution
    {
        $config = $check->config;
        $driver = strtolower((string)($config['driver'] ?? 'mysql'));
        $host = (string)($config['host'] ?? 'localhost');
        $timeout = max(1, (int)($config['timeout'] ?? 5));
        
        $port = (int)($config['port'] ?? $this->getDefaultPort($driver));
        $database = (string)($config['database'] ?? '');
        $username = (string)($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');
        $query = (string)($config['query'] ?? '');
        $expectedResult = (string)($config['expected_result'] ?? '');
        $dsn = (string)($config['dsn'] ?? '');

        $now = gmdate('Y-m-d H:i:s');
        $startTime = microtime(true);
        
        $tcpReachable = false;
        $authOk = false;
        $queryOk = null;
        $queryResultStr = null;
        $serverVersion = null;
        $redisVersion = null;
        $errorMessage = null;
        
        $state = UplinkState::UNKNOWN;
        
        if ($driver === 'sqlite') {
            $tcpReachable = true; // SQLite is local file, skip TCP
        } else {
            // TCP Check
            $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
            if ($fp) {
                $tcpReachable = true;
                fclose($fp);
            } else {
                $tcpReachable = false;
                $errorMessage = "TCP connection failed: $errstr ($errno)";
                $state = UplinkState::OFFLINE;
            }
        }

        if ($tcpReachable) {
            try {
                if ($driver === 'redis') {
                    $this->checkRedis($host, $port, $timeout, $password, $redisVersion);
                    $authOk = true;
                    $state = UplinkState::EXCELLENT;
                } else {
                    $pdoDsn = $dsn ?: $this->buildDsn($driver, $host, $port, $database);
                    $pdo = new PDO($pdoDsn, $username, $password, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => $timeout,
                    ]);
                    $authOk = true;
                    $serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
                    $state = UplinkState::EXCELLENT;

                    if ($query !== '') {
                        $stmt = $pdo->query($query);
                        $result = $stmt ? $stmt->fetchColumn() : false;
                        
                        if ($result !== false) {
                            $queryOk = true;
                            $queryResultStr = mb_substr((string)$result, 0, 255);
                            
                            if ($expectedResult !== '' && $queryResultStr !== $expectedResult) {
                                $queryOk = false;
                                $errorMessage = sprintf("Query result mismatch. Expected '%s', got '%s'", $expectedResult, $queryResultStr);
                                $state = UplinkState::DEGRADED;
                            }
                        } else {
                            $queryOk = false;
                            $errorMessage = 'Query execution failed or returned no results';
                            $state = UplinkState::DEGRADED;
                        }
                    }
                }
            } catch (PDOException $e) {
                $authOk = false;
                $errorMessage = 'Database error: ' . $e->getMessage();
                $state = UplinkState::DEGRADED;
            } catch (Throwable $e) {
                $authOk = false;
                $errorMessage = 'Connection error: ' . $e->getMessage();
                $state = UplinkState::DEGRADED;
            }
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        if ($state === UplinkState::EXCELLENT || $state === UplinkState::GOOD) {
            if ($durationMs <= 50) {
                $state = UplinkState::EXCELLENT;
            } elseif ($durationMs <= 1000) {
                $state = UplinkState::GOOD;
            } else {
                $state = UplinkState::DEGRADED;
                if ($errorMessage === null) {
                    $errorMessage = sprintf('Slow connection time: %.0f ms', $durationMs);
                }
            }
        }

        $resultData = [
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'duration_ms' => $durationMs,
            'tcp_reachable' => $tcpReachable,
            'auth_ok' => $authOk,
            'query_ok' => $queryOk,
            'query_result' => $queryResultStr,
            'server_version' => $serverVersion,
            'redis_version' => $redisVersion,
        ];

        return new CheckExecution(
            id: Ulid::generate(),
            checkId: $check->id,
            status: $state,
            durationMs: $durationMs,
            resultData: $resultData,
            errorMessage: $errorMessage,
            executedAt: $now
        );
    }

    private function getDefaultPort(string $driver): int
    {
        return match ($driver) {
            'postgres', 'pgsql' => 5432,
            'redis' => 6379,
            'sqlite' => 0,
            default => 3306, // mysql, mariadb
        };
    }

    public function buildDsn(string $driver, string $host, int $port, string $database): string
    {
        if ($driver === 'sqlite') {
            return "sqlite:{$database}";
        }
        
        $pdoDriver = $driver === 'postgres' ? 'pgsql' : 'mysql';
        return sprintf("%s:host=%s;port=%d;dbname=%s", $pdoDriver, $host, $port, $database);
    }

    /**
     * @throws \Exception
     */
    private function checkRedis(string $host, int $port, int $timeout, string $password, ?string &$redisVersion): void
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            throw new \Exception("Redis connection failed: $errstr");
        }
        
        stream_set_timeout($fp, $timeout);
        
        if ($password !== '') {
            fwrite($fp, "AUTH $password\r\n");
            $authResponse = fgets($fp);
            if ($authResponse === false || !str_starts_with($authResponse, '+OK')) {
                fclose($fp);
                throw new \Exception("Redis authentication failed");
            }
        }
        
        fwrite($fp, "PING\r\n");
        $pingResponse = fgets($fp);
        if ($pingResponse === false || !str_starts_with($pingResponse, '+PONG')) {
            fclose($fp);
            throw new \Exception("Redis PING failed");
        }
        
        fwrite($fp, "INFO Server\r\n");
        $infoResponse = '';
        $inInfo = false;
        
        // Very basic parsing for redis_version
        while (($line = fgets($fp)) !== false) {
            if (str_starts_with($line, '$')) {
                $inInfo = true;
                continue;
            }
            if ($inInfo && str_starts_with($line, 'redis_version:')) {
                $parts = explode(':', trim($line));
                if (isset($parts[1])) {
                    $redisVersion = $parts[1];
                }
                break;
            }
            if ($line === "\r\n") {
                break;
            }
        }
        
        fclose($fp);
    }
}
