<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

class IsolatedDatabase
{
    public static function credentials(): array
    {
        $path = dirname(__DIR__, 2).'/.mysql-testing.local.json';
        if (is_link($path) || ! is_file($path) || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('Missing private mode-0600 disposable MySQL configuration.');
        }
        $config = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($config['purpose'] ?? '') !== 'authorized-disposable-jucans-phase2-mysql-test' || ($config['database'] ?? '') !== 'jucans_phase2_mysql_test' || ($config['socket'] ?? '') !== '/tmp/mysql.sock' || ! preg_match('/^jucans_p2_[a-f0-9]{8}$/', $config['username'] ?? '') || empty($config['password']) || empty($config['server_uuid'])) {
            throw new RuntimeException('Invalid disposable MySQL identity; refusing database access.');
        }

        return $config;
    }

    public static function configureMySql(): void
    {
        $config = self::credentials();
        $variables = [
            'APP_ENV' => 'testing', 'JUCANS_MYSQL_TEST' => '1',
            'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $config['database'],
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
            'DB_SOCKET' => $config['socket'], 'DB_HOST' => 'localhost', 'DB_PORT' => '3306',
            'DATABASE_URL' => '', 'DB_URL' => '', 'CACHE_DRIVER' => 'array',
            'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array', 'BCRYPT_ROUNDS' => '4',
        ];
        foreach ($variables as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        if (is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
            throw new RuntimeException('Cached application configuration is forbidden for disposable MySQL tests.');
        }
    }

    public static function assertSafe(Application $app): void
    {
        if (! $app->environment('testing')) {
            throw new RuntimeException('Database tests require APP_ENV=testing.');
        }
        $driver = $app['config']->get('database.default');
        $connection = $app['config']->get("database.connections.{$driver}");
        if (getenv('JUCANS_MYSQL_TEST') !== '1' && $driver === 'sqlite' && ($connection['database'] ?? '') === ':memory:' && empty($connection['url'])) {
            return;
        }
        if ($driver !== 'mysql' || getenv('JUCANS_MYSQL_TEST') !== '1') {
            throw new RuntimeException('Tests require SQLite :memory: or the explicitly guarded disposable MySQL configuration.');
        }
        $expected = self::credentials();
        if ($app->configurationIsCached() || ($connection['database'] ?? '') !== $expected['database'] || ($connection['username'] ?? '') !== $expected['username'] || ($connection['password'] ?? '') !== $expected['password'] || ($connection['unix_socket'] ?? '') !== $expected['socket'] || ! empty($connection['url']) || isset($connection['read']) || isset($connection['write']) || getenv('DATABASE_URL') || getenv('DB_URL')) {
            throw new RuntimeException('Effective MySQL connection does not match the authorized disposable identity.');
        }
        $db = $app['db']->connection();
        try {
            $identity = $db->selectOne('SELECT DATABASE() AS db, CURRENT_USER() AS account, @@server_uuid AS server_uuid, @@foreign_key_checks AS foreign_keys, @@partial_revokes AS partial_revokes');
            if ($identity->db !== $expected['database'] || $identity->account !== $expected['username'].'@localhost' || $identity->server_uuid !== $expected['server_uuid'] || (int) $identity->foreign_keys !== 1) {
                throw new RuntimeException('Server identity or database isolation check failed.');
            }
            $target = '`'.($identity->partial_revokes ? $expected['database'] : addcslashes($expected['database'], '_%')).'`.*';
            $allowed = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'REFERENCES', 'TRIGGER'];
            foreach ($db->select('SHOW GRANTS FOR CURRENT_USER') as $grant) {
                $sql = array_values((array) $grant)[0];
                if (! preg_match('/^GRANT (.+) ON (.+) TO /', $sql, $match) || str_contains($sql, 'WITH GRANT OPTION')) {
                    throw new RuntimeException('Unexpected account grants; refusing test cleanup.');
                }
                if ($match[1] === 'USAGE' && $match[2] === '*.*') {
                    continue;
                }
                if ($match[2] !== $target || array_diff(explode(', ', $match[1]), $allowed)) {
                    throw new RuntimeException('The test account has privileges outside the authorized schema.');
                }
            }
        } catch (\Throwable $failure) {
            // Do not let authentication/connection exceptions print account credentials.
            throw new RuntimeException('Disposable MySQL identity/grant verification failed; details suppressed.');
        }
    }
}
