<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Support;

use Illuminate\Database\Migrations\Migrator;

/**
 * Pending migrations for the default connection (all module paths) and a
 * conservative scan for destructive statements. The web upgrade refuses any
 * pending migration that looks destructive; those must be run from the CLI
 * after a verified backup (UPGRADE-GUIDE.md).
 */
final class MigrationStatus
{
    private const DESTRUCTIVE_PATTERNS = [
        '/->\s*drop(Column|IfExists|Index|Unique|Foreign|Primary|Columns|Morphs|Timestamps|SoftDeletes)?\s*\(/i',
        '/Schema::\s*drop(IfExists)?\s*\(/i',
        '/->\s*renameColumn\s*\(/i',
        '/Schema::\s*rename\s*\(/i',
        '/->\s*change\s*\(\s*\)/i',
        '/\b(TRUNCATE|DROP\s+TABLE|DROP\s+COLUMN|DELETE\s+FROM)\b/i',
        '/->\s*(truncate|delete)\s*\(/i',
    ];

    public function __construct(private readonly Migrator $migrator) {}

    /**
     * @return array<string, string> name => file path
     */
    public function files(): array
    {
        $paths = array_merge([database_path('migrations')], $this->migrator->paths());

        return $this->migrator->getMigrationFiles($paths);
    }

    public function repositoryExists(): bool
    {
        try {
            return $this->migrator->repositoryExists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function ran(): array
    {
        if (! $this->repositoryExists()) {
            return [];
        }

        return array_values($this->migrator->getRepository()->getRan());
    }

    /**
     * @return list<array{name: string, destructive: bool}>
     */
    public function pending(): array
    {
        $ran = array_flip($this->ran());
        $out = [];
        foreach ($this->files() as $name => $path) {
            if (! isset($ran[$name])) {
                $out[] = ['name' => $name, 'destructive' => $this->isDestructive($path)];
            }
        }

        return $out;
    }

    /**
     * Only the up() body counts: down() legitimately drops what up() created.
     */
    public function isDestructive(string $path): bool
    {
        $code = (string) @file_get_contents($path);
        $up = $code;
        if (preg_match('/function\s+up\s*\([^)]*\)\s*(:\s*\w+\s*)?\{/i', $code, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $down = stripos($code, 'function down', $start);
            $up = substr($code, $start, $down === false ? null : $down - $start);
        }
        foreach (self::DESTRUCTIVE_PATTERNS as $p) {
            if (preg_match($p, $up) === 1) {
                return true;
            }
        }

        return false;
    }
}
