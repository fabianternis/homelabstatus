<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Checker\DatabaseChecker;
use PHPUnit\Framework\TestCase;

class DatabaseCheckerTest extends TestCase
{
    private DatabaseChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new DatabaseChecker();
    }

    public function testSupportsAllTypes(): void
    {
        $this->assertTrue($this->checker->supports('database'));
        $this->assertTrue($this->checker->supports('db'));
        $this->assertTrue($this->checker->supports('mysql'));
        $this->assertTrue($this->checker->supports('postgres'));
        $this->assertTrue($this->checker->supports('redis'));
        $this->assertTrue($this->checker->supports('mariadb'));
    }

    public function testSupportsReturnsFalseForUnsupportedTypes(): void
    {
        $this->assertFalse($this->checker->supports('http'));
        $this->assertFalse($this->checker->supports('tcp'));
        $this->assertFalse($this->checker->supports('ping'));
    }

    public function testDefaultPortResolution(): void
    {
        $reflection = new \ReflectionClass(DatabaseChecker::class);
        $method = $reflection->getMethod('getDefaultPort');

        $this->assertEquals(3306, $method->invoke($this->checker, 'mysql'));
        $this->assertEquals(3306, $method->invoke($this->checker, 'mariadb'));
        $this->assertEquals(5432, $method->invoke($this->checker, 'postgres'));
        $this->assertEquals(5432, $method->invoke($this->checker, 'pgsql'));
        $this->assertEquals(6379, $method->invoke($this->checker, 'redis'));
        $this->assertEquals(0, $method->invoke($this->checker, 'sqlite'));
    }

    public function testDsnBuildingLogic(): void
    {
        $this->assertEquals(
            'mysql:host=127.0.0.1;port=3306;dbname=test_db',
            $this->checker->buildDsn('mysql', '127.0.0.1', 3306, 'test_db')
        );

        $this->assertEquals(
            'pgsql:host=localhost;port=5432;dbname=my_pg',
            $this->checker->buildDsn('postgres', 'localhost', 5432, 'my_pg')
        );

        $this->assertEquals(
            'sqlite:/path/to/db.sqlite',
            $this->checker->buildDsn('sqlite', 'localhost', 0, '/path/to/db.sqlite')
        );
    }
}
