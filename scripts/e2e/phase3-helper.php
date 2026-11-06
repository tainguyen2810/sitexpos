<?php

/**
 * Helper for scripts/e2e/phase3-worker-restart.sh (dev/CI only, never production).
 *   php phase3-helper.php setup <paidRetry 0|1>      → prints workspace id
 *   php phase3-helper.php start <workspaceId> <prompt> → prints run id
 *   php phase3-helper.php status <workspaceId> <runId> → prints JSON summary
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Domain\AiBudgetReservation;
use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;
use SiteXPost\Modules\AiGateway\Domain\AiRequest;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workflows\Domain\DeadLetter;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\WorkflowEngine;
use SiteXPost\Modules\Workspace\Actions\CreateWorkspace;
use SiteXPost\Modules\Workspace\Domain\Workspace;

require __DIR__.'/../../backend/vendor/autoload.php';
$app = require __DIR__.'/../../backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->environment('production')) {
    fwrite(STDERR, "Refusing to run in production\n");
    exit(1);
}

$cmd = $argv[1] ?? '';
$ctx = app(TenantContext::class);

switch ($cmd) {
    case 'setup':
        $fake = AiModel::query()->where('model_key', 'fake/fake-echo')->firstOrFail();
        AiProvider::query()->where('code', 'fake')->update(['max_retries' => 0]);
        AiTaskRoute::query()->where('task_key', 'system.test')->update([
            'primary_model_id' => $fake->id, 'fallback_model_ids' => json_encode([]),
            'allow_paid_retry' => ($argv[2] ?? '0') === '1', 'max_attempts' => 3,
        ]);
        $user = new User;
        $user->forceFill([
            'name' => 'E2E Worker', 'email' => 'e2e-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'Password12345', 'email_verified_at' => now(),
        ])->save();
        echo app(CreateWorkspace::class)->handle($user, ['name' => 'E2E Phase 3'])->id;
        break;

    case 'start':
        $ws = Workspace::query()->findOrFail($argv[2]);
        echo $ctx->runAs($ws, null, fn () => app(WorkflowEngine::class)->start('ai.test', [
            'task_key' => 'system.test', 'prompt' => $argv[3], 'structured' => false, 'web_search' => false,
        ])[0]->id);
        break;

    case 'status':
        $ws = Workspace::query()->findOrFail($argv[2]);
        echo json_encode($ctx->runAs($ws, null, function () use ($argv) {
            $run = WorkflowRun::query()->with('steps')->findOrFail($argv[3]);

            return [
                'status' => $run->status->value,
                'error' => $run->error_code,
                'attempts' => $run->attempts,
                'steps' => $run->steps->mapWithKeys(fn ($s) => [$s->key => $s->status->value])->all(),
                'ai_requests' => AiRequest::query()->where('workflow_run_id', $run->id)->count(),
                'dead_letters' => DeadLetter::query()->where('workflow_run_id', $run->id)->pluck('status')->all(),
                'reservations' => AiBudgetReservation::query()->where('idempotency_key', 'like', "wf:{$run->id}:%")->pluck('status')->all(),
            ];
        }));
        break;

    default:
        fwrite(STDERR, "usage: setup|start|status\n");
        exit(2);
}
