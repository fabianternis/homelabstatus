<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Check;
use App\Enum\UplinkState;
use App\Service\Checker\S3BucketChecker;
use PHPUnit\Framework\TestCase;

class S3BucketCheckerTest extends TestCase
{
    private S3BucketChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new S3BucketChecker();
    }

    public function testSupports(): void
    {
        $this->assertTrue($this->checker->supports('s3'));
        $this->assertTrue($this->checker->supports('bucket'));
        $this->assertTrue($this->checker->supports('object_storage'));
        
        $this->assertFalse($this->checker->supports('http'));
        $this->assertFalse($this->checker->supports('tcp'));
        $this->assertFalse($this->checker->supports('invalid'));
    }

    public function testEndpointResolutionAws(): void
    {
        $url = $this->checker->resolveEndpointUrl('aws', 'my-bucket', 'eu-central-1', '', '');
        $this->assertEquals('https://my-bucket.s3.eu-central-1.amazonaws.com/', $url);

        $urlWithObject = $this->checker->resolveEndpointUrl('aws', 'my-bucket', 'eu-central-1', '', 'test.txt');
        $this->assertEquals('https://my-bucket.s3.eu-central-1.amazonaws.com/test.txt', $urlWithObject);
    }

    public function testEndpointResolutionHetzner(): void
    {
        $url = $this->checker->resolveEndpointUrl('hetzner', 'my-bucket', 'nbg1', '', '');
        $this->assertEquals('https://nbg1.your-objectstorage.com/my-bucket/', $url);

        $urlWithObject = $this->checker->resolveEndpointUrl('hetzner', 'my-bucket', 'nbg1', '', 'path/test.png');
        $this->assertEquals('https://nbg1.your-objectstorage.com/my-bucket/path/test.png', $urlWithObject);
    }

    public function testEndpointResolutionR2(): void
    {
        $endpoint = 'https://123456789.r2.cloudflarestorage.com';
        $url = $this->checker->resolveEndpointUrl('r2', 'my-bucket', '', $endpoint, '');
        $this->assertEquals('https://123456789.r2.cloudflarestorage.com/my-bucket/', $url);
    }

    public function testEndpointResolutionCustom(): void
    {
        $endpoint = 'https://minio.local';
        $url = $this->checker->resolveEndpointUrl('custom', 'my-bucket', '', $endpoint, 'file.txt');
        $this->assertEquals('https://minio.local/my-bucket/file.txt', $url);
    }

    public function testEvaluateResultExcellent(): void
    {
        $check = new Check();
        
        $reflection = new \ReflectionClass(S3BucketChecker::class);
        $method = $reflection->getMethod('evaluateResult');
        
        $info = ['http_code' => 200, 'total_time' => 0.3]; // 300ms
        $result = $method->invoke($this->checker, $check, 'https://test', 'aws', 'bucket', 'region', '', $info, '', 0, '2026-01-01 10:00:00');
        
        $this->assertEquals(UplinkState::EXCELLENT, $result->status);
        $this->assertEquals(300.0, $result->durationMs);
        $this->assertNull($result->errorMessage);
    }

    public function testEvaluateResultGood(): void
    {
        $check = new Check();
        
        $reflection = new \ReflectionClass(S3BucketChecker::class);
        $method = $reflection->getMethod('evaluateResult');
        
        $info = ['http_code' => 204, 'total_time' => 0.8]; // 800ms
        $result = $method->invoke($this->checker, $check, 'https://test', 'aws', 'bucket', 'region', '', $info, '', 0, '2026-01-01 10:00:00');
        
        $this->assertEquals(UplinkState::GOOD, $result->status);
    }

    public function testEvaluateResultDegradedSlow(): void
    {
        $check = new Check();
        
        $reflection = new \ReflectionClass(S3BucketChecker::class);
        $method = $reflection->getMethod('evaluateResult');
        
        $info = ['http_code' => 200, 'total_time' => 1.5]; // 1500ms
        $result = $method->invoke($this->checker, $check, 'https://test', 'aws', 'bucket', 'region', '', $info, '', 0, '2026-01-01 10:00:00');
        
        $this->assertEquals(UplinkState::DEGRADED, $result->status);
    }

    public function testEvaluateResultOfflineSlow(): void
    {
        $check = new Check();
        
        $reflection = new \ReflectionClass(S3BucketChecker::class);
        $method = $reflection->getMethod('evaluateResult');
        
        $info = ['http_code' => 200, 'total_time' => 3.0]; // 3000ms
        $result = $method->invoke($this->checker, $check, 'https://test', 'aws', 'bucket', 'region', '', $info, '', 0, '2026-01-01 10:00:00');
        
        $this->assertEquals(UplinkState::OFFLINE, $result->status);
        $this->assertStringContainsString('Response time too slow', $result->errorMessage);
    }

    public function testEvaluateResultForbidden(): void
    {
        $check = new Check();
        
        $reflection = new \ReflectionClass(S3BucketChecker::class);
        $method = $reflection->getMethod('evaluateResult');
        
        $info = ['http_code' => 403, 'total_time' => 0.1];
        $result = $method->invoke($this->checker, $check, 'https://test', 'aws', 'bucket', 'region', '', $info, '', 0, '2026-01-01 10:00:00');
        
        $this->assertEquals(UplinkState::DEGRADED, $result->status);
        $this->assertStringContainsString('Access denied', $result->errorMessage);
    }

    public function testEvaluateResultNotFound(): void
    {
        $check = new Check();
        
        $reflection = new \ReflectionClass(S3BucketChecker::class);
        $method = $reflection->getMethod('evaluateResult');
        
        $info = ['http_code' => 404, 'total_time' => 0.1];
        $result = $method->invoke($this->checker, $check, 'https://test', 'aws', 'bucket', 'region', '', $info, '', 0, '2026-01-01 10:00:00');
        
        $this->assertEquals(UplinkState::OFFLINE, $result->status);
        $this->assertStringContainsString('not found', $result->errorMessage);
    }

    public function testEvaluateResultConnectionError(): void
    {
        $check = new Check();
        
        $reflection = new \ReflectionClass(S3BucketChecker::class);
        $method = $reflection->getMethod('evaluateResult');
        
        $info = ['http_code' => 0, 'total_time' => 0.0];
        $result = $method->invoke($this->checker, $check, 'https://test', 'aws', 'bucket', 'region', '', $info, 'Connection timed out', 28, '2026-01-01 10:00:00');
        
        $this->assertEquals(UplinkState::OFFLINE, $result->status);
        $this->assertEquals('Connection timed out', $result->errorMessage);
    }
}
