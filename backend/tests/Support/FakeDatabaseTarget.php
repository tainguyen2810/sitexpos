<?php

declare(strict_types=1);

namespace Tests\Support;

use SiteXPost\Modules\Installer\Support\DatabaseTarget;

/**
 * Installer database target for tests: reports a configurable inspection
 * result and keeps using the test connection (SQLite in memory).
 */
final class FakeDatabaseTarget extends DatabaseTarget
{
    /** @var array{connected: bool, error_code: ?string, server_version: ?string, version_ok: bool, table_count: int, has_sitex_tables: bool, empty: bool, can_create: bool} */
    public array $result = ['connected' => true, 'error_code' => null, 'server_version' => '8.4.3', 'version_ok' => true, 'table_count' => 0, 'has_sitex_tables' => false, 'empty' => true, 'can_create' => true];

    public function __construct() {}

    public function inspect(array $c): array
    {
        return $this->result;
    }

    public function configure(array $c): string
    {
        return (string) config('database.default');
    }
}
