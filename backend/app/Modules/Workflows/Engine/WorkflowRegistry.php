<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Engine;

use Illuminate\Contracts\Container\Container;

/**
 * Workflow type → definition. Modules register their definitions in their
 * service provider: `$registry->register(MyWorkflow::class)`.
 */
final class WorkflowRegistry
{
    /** @var array<string, class-string<WorkflowDefinition>> */
    private array $types = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<WorkflowDefinition>  $class
     */
    public function register(string $class): void
    {
        $definition = $this->container->make($class);
        $this->types[$definition->type()] = $class;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function get(string $type): WorkflowDefinition
    {
        if (! $this->has($type)) {
            throw new \InvalidArgumentException("Unknown workflow type [{$type}].");
        }

        return $this->container->make($this->types[$type]);
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->types);
    }
}
