<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Installer\Support;

use Illuminate\Support\Facades\DB;

/**
 * The database the installer writes into. Production: MySQL from the wizard.
 * Tests bind a fake target (the wizard itself only offers MySQL).
 */
class DatabaseTarget
{
    public function __construct(private readonly DatabaseProbe $probe) {}

    /**
     * @param  array{host: string, port: int, database: string, username: string, password: ?string}  $c
     * @return array{connected: bool, error_code: ?string, server_version: ?string, version_ok: bool, table_count: int, has_sitex_tables: bool, empty: bool, can_create: bool}
     */
    public function inspect(array $c): array
    {
        return $this->probe->inspect($c);
    }

    /**
     * Points the running process at the target and returns the connection name.
     *
     * @param  array{host: string, port: int, database: string, username: string, password: ?string}  $c
     */
    public function configure(array $c): string
    {
        config([
            'database.connections.mysql.host' => $c['host'],
            'database.connections.mysql.port' => $c['port'],
            'database.connections.mysql.database' => $c['database'],
            'database.connections.mysql.username' => $c['username'],
            'database.connections.mysql.password' => (string) ($c['password'] ?? ''),
            'database.connections.mysql.url' => null,
            'database.default' => 'mysql',
        ]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        return 'mysql';
    }
}
