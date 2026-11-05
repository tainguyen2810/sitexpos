<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Modules;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base provider for a module: auto-loads its migrations, routes and policies.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected array $policies = [];

    public function boot(): void
    {
        $dir = dirname((string) (new ReflectionClass($this))->getFileName());

        if (is_dir($dir.'/Database/Migrations')) {
            $this->loadMigrationsFrom($dir.'/Database/Migrations');
        }

        if (is_file($dir.'/routes.php')) {
            $this->loadRoutesFrom($dir.'/routes.php');
        }

        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->bootModule();
    }

    protected function bootModule(): void {}
}
