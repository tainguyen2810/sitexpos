<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Installer\Support;

use PDO;
use PDOException;

/**
 * Tests MySQL credentials entered in the wizard with a plain PDO connection
 * (the Laravel connection may not be configured yet) and inspects the target
 * database so the installer never writes into a database that already holds
 * data. The password is never logged or echoed back.
 */
final class DatabaseProbe
{
    /**
     * @param  array{host: string, port: int, database: string, username: string, password: ?string}  $c
     * @return array{connected: bool, error_code: ?string, server_version: ?string, version_ok: bool, table_count: int, has_sitex_tables: bool, empty: bool, can_create: bool}
     */
    public function inspect(array $c): array
    {
        $result = ['connected' => false, 'error_code' => null, 'server_version' => null, 'version_ok' => false, 'table_count' => 0, 'has_sitex_tables' => false, 'empty' => false, 'can_create' => false];

        try {
            $pdo = $this->connect($c);
        } catch (PDOException $e) {
            $result['error_code'] = $this->classify($e);

            return $result;
        }

        $result['connected'] = true;
        $q = $pdo->query('SELECT VERSION()');
        $version = $q !== false ? (string) $q->fetchColumn() : '';
        $result['server_version'] = $version;
        $numeric = preg_replace('/[^0-9.].*$/', '', $version) ?? '';
        $isMaria = stripos($version, 'mariadb') !== false;
        $result['version_ok'] = ! $isMaria && version_compare($numeric, '8.0.0', '>=');

        $stmt = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
        $stmt->execute([$c['database']]);
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $result['table_count'] = count($tables);
        $result['empty'] = $tables === [];
        $result['has_sitex_tables'] = in_array('migrations', $tables, true) && in_array('users', $tables, true);

        // CREATE privilege on the target schema (needed by migrations).
        try {
            $g = $pdo->query('SHOW GRANTS FOR CURRENT_USER()');
            $grants = implode("\n", $g !== false ? $g->fetchAll(PDO::FETCH_COLUMN) : []);
            $result['can_create'] = (bool) preg_match('/GRANT (ALL PRIVILEGES|.*\bCREATE\b.*) ON (\*|`?'.preg_quote($c['database'], '/').'`?)\.\*/i', $grants);
        } catch (PDOException) {
            $result['can_create'] = false;
        }

        return $result;
    }

    /**
     * @param  array{host: string, port: int, database: string, username: string, password: ?string}  $c
     */
    public function connect(array $c): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']);

        return new PDO($dsn, $c['username'], (string) ($c['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    /**
     * Stable identity of a target (no password) used to recognise a resumed install.
     *
     * @param  array{host: string, port: int, database: string, username: string}  $c
     */
    public static function fingerprint(array $c): string
    {
        return hash('sha256', strtolower($c['host']).'|'.$c['port'].'|'.$c['database'].'|'.$c['username']);
    }

    private function classify(PDOException $e): string
    {
        $code = (string) ($e->errorInfo[1] ?? $e->getCode());

        return match ($code) {
            '1045' => 'access_denied',
            '1049' => 'unknown_database',
            '1044' => 'database_forbidden',
            '2002', '2003', '2005', '2006' => 'host_unreachable',
            default => 'connection_failed',
        };
    }
}
