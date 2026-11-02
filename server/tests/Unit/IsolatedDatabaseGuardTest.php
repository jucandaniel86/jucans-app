<?php

namespace Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\IsolatedDatabase;

class IsolatedDatabaseGuardTest extends TestCase
{
    private function application(string $environment, string $driver, array $connection): Application
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('env', $environment);
        $app->instance('config', new Repository(['database' => ['default' => $driver, 'connections' => [$driver => $connection]]]));
        $app->bind('db', function () {
            throw new RuntimeException('Unsafe connection must not be opened.');
        });

        return $app;
    }

    public function test_in_memory_sqlite_is_allowed_without_connecting(): void
    {
        $previous = getenv('JUCANS_MYSQL_TEST');
        putenv('JUCANS_MYSQL_TEST');
        try {
            IsolatedDatabase::assertSafe($this->application('testing', 'sqlite', ['database' => ':memory:', 'url' => null]));
            $this->addToAssertionCount(1);
        } finally {
            $previous === false ? putenv('JUCANS_MYSQL_TEST') : putenv('JUCANS_MYSQL_TEST='.$previous);
        }
    }

    public function test_non_testing_environments_and_disk_databases_fail_before_connecting(): void
    {
        foreach ([['production', 'sqlite', ['database' => ':memory:']], ['testing', 'sqlite', ['database' => '/tmp/not-disposable.sqlite']], ['testing', 'sqlite', ['database' => ':memory:', 'url' => 'sqlite:///application.sqlite']], ['testing', 'mysql', ['database' => 'application']]] as [$environment, $driver, $config]) {
            try {
                IsolatedDatabase::assertSafe($this->application($environment, $driver, $config));
                $this->fail('Expected isolation guard rejection.');
            } catch (RuntimeException $failure) {
                $this->assertNotSame('Unsafe connection must not be opened.', $failure->getMessage());
            }
        }
    }

    public function test_mysql_admin_ddl_rejects_cross_schema_and_non_ddl_before_opening_admin_connection(): void
    {
        if (getenv('JUCANS_MYSQL_TEST') !== '1') {
            $this->markTestSkipped('Requires the explicitly provisioned local MySQL identity.');
        }
        $identity = IsolatedDatabase::credentials();
        $connection = new \Tests\Support\IsolatedMySqlConnection(function () {
            throw new RuntimeException('PDO must not be opened.');
        }, $identity['database'], '', ['username' => $identity['username'], 'unix_socket' => $identity['socket']]);
        $method = new \ReflectionMethod($connection, 'ddlConnection');
        $method->setAccessible(true);
        foreach (['CREATE TABLE `unrelated_app`.`blocked` (`id` INT)', 'DROP DATABASE `unrelated_app`', 'UPDATE `users` SET `id` = 1', 'CREATE TABLE `blocked` AS SELECT * FROM `unrelated_app`.`users`'] as $sql) {
            try {
                $method->invoke($connection, $sql);
                $this->fail('Unsafe admin SQL must be rejected.');
            } catch (RuntimeException $failure) {
                $this->assertNotSame('PDO must not be opened.', $failure->getMessage());
            }
        }
    }

    public function test_mysql_admin_ddl_rejects_a_different_database_before_connecting(): void
    {
        if (getenv('JUCANS_MYSQL_TEST') !== '1') {
            $this->markTestSkipped('Requires the explicitly provisioned local MySQL identity.');
        }
        $identity = IsolatedDatabase::credentials();
        $connection = new \Tests\Support\IsolatedMySqlConnection(function () {
            throw new RuntimeException('PDO must not be opened.');
        }, 'unrelated_app', '', ['username' => $identity['username'], 'unix_socket' => $identity['socket']]);
        try {
            $connection->statement('CREATE TABLE `blocked` (`id` INT)');
            $this->fail('Unsafe target must be rejected.');
        } catch (RuntimeException $failure) {
            $this->assertStringContainsString('exact disposable test identity', $failure->getMessage());
        }
    }

    public function test_mysql_mode_cannot_silently_fall_back_to_sqlite(): void
    {
        $previous = getenv('JUCANS_MYSQL_TEST');
        putenv('JUCANS_MYSQL_TEST=1');
        try {
            IsolatedDatabase::assertSafe($this->application('testing', 'sqlite', ['database' => ':memory:', 'url' => null]));
            $this->fail('Explicit MySQL verification must not fall back to SQLite.');
        } catch (RuntimeException $failure) {
            $this->assertNotSame('Unsafe connection must not be opened.', $failure->getMessage());
        } finally {
            $previous === false ? putenv('JUCANS_MYSQL_TEST') : putenv('JUCANS_MYSQL_TEST='.$previous);
        }
    }
}
