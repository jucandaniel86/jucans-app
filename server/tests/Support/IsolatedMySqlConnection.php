<?php

namespace Tests\Support;

use Illuminate\Database\MySqlConnection;
use PDO;
use RuntimeException;

/** Local test-only DDL delegation; application DML always uses the restricted PDO. */
class IsolatedMySqlConnection extends MySqlConnection
{
    private ?PDO $ddlPdo = null;

    private function ddlConnection(string $sql): PDO
    {
        $identity = IsolatedDatabase::credentials();
        if (getenv('APP_ENV') !== 'testing' || getenv('JUCANS_MYSQL_TEST') !== '1' || empty($identity['admin_ddl_authorized']) || $this->getDatabaseName() !== $identity['database'] || $this->getConfig('username') !== $identity['username'] || $this->getConfig('unix_socket') !== $identity['socket']) {
            throw new RuntimeException('Admin DDL is authorized only for the exact disposable test identity.');
        }
        if (! preg_match('/^\s*(?:(?:CREATE|DROP)\s+(?:TABLE|TRIGGER|INDEX|VIEW)|ALTER\s+TABLE|TRUNCATE\s+(?:TABLE\s+)?|SET\s+FOREIGN_KEY_CHECKS\s*=)/i', $sql) || preg_match('/\b(?:USE|GRANT|REVOKE|DATABASE|SCHEMA)\b/i', $sql)) {
            throw new RuntimeException('Refusing non-schema-local administrative SQL.');
        }
        preg_match_all('/(?:`([^`]+)`|\b([a-zA-Z_][a-zA-Z0-9_]*))\s*\./', $sql, $qualifiers, PREG_SET_ORDER);
        foreach ($qualifiers as $qualifier) {
            $name = $qualifier[1] ?: ($qualifier[2] ?? '');
            if (! in_array(strtolower($name), ['new', 'old', $identity['database']], true)) {
                throw new RuntimeException('Cross-schema administrative DDL is forbidden.');
            }
        }
        if ($this->ddlPdo === null) {
            try {
                $pdo = new PDO('mysql:unix_socket='.$identity['socket'].';dbname='.$identity['database'].';charset=utf8mb4', $identity['ddl_username'] ?? '', $identity['ddl_password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $server = $pdo->query('SELECT DATABASE() AS db, @@server_uuid AS server_uuid')->fetch(PDO::FETCH_ASSOC);
                if ($server['db'] !== $identity['database'] || $server['server_uuid'] !== $identity['server_uuid']) {
                    throw new RuntimeException('Admin DDL server identity mismatch.');
                }
                $this->ddlPdo = $pdo;
            } catch (\Throwable) {
                throw new RuntimeException('Approved local schema-only DDL connection is unavailable; details suppressed.');
            }
        }

        return $this->ddlPdo;
    }

    private function administrative(string $query, array $bindings = []): bool
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }
            $statement = $this->ddlConnection($query)->prepare($query);

            return $statement->execute($bindings);
        });
    }

    public function statement($query, $bindings = [])
    {
        if (preg_match('/^\s*SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]\s*;?\s*$/i', $query)) {
            // migrate:fresh toggles enforcement for its DROP sequence on both sessions.
            $this->administrative($query, $bindings);

            return parent::statement($query, $bindings);
        }
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query)) {
            return $this->administrative($query, $bindings);
        }

        return parent::statement($query, $bindings);
    }

    public function unprepared($query)
    {
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query)) {
            return $this->administrative($query);
        }

        return parent::unprepared($query);
    }
}
