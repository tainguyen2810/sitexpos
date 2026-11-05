<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Actions;

use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workspace\Domain\Workspace;

final class UpdateWorkspace
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Workspace $workspace, array $data): Workspace
    {
        $workspace->fill($data);
        $changes = $workspace->getDirty();
        $original = array_intersect_key($workspace->getOriginal(), $changes);
        $workspace->save();

        if ($changes !== []) {
            Audit::record('workspace.updated', $workspace, ['before' => $original, 'after' => $changes]);
        }

        return $workspace;
    }
}
