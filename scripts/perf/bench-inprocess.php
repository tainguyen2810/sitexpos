<?php
// Usage (NEVER against production): APP_ENV=local DB_DATABASE=<demo db> php scripts/perf/bench-inprocess.php owner@demo.sitexpost.test 20 /api/v1/me "/api/v1/workspaces/{ws}/analytics/overview"
// In-process latency benchmark (no network, no PHP-FPM): boots the app once, then handles
// each request through the HTTP kernel as the demo owner. Measures server-side time + query count.
require __DIR__.'/../../backend/vendor/autoload.php';
$app = require __DIR__.'/../../backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($app->environment('production')) {
    fwrite(STDERR, "Refusing to benchmark a production environment.\n");
    exit(1);
}
use Illuminate\Support\Facades\DB;
$user = SiteXPost\Modules\Identity\Domain\User::query()->where('email', $argv[1])->firstOrFail();
$ws = DB::table('workspace_members')->where('user_id', $user->id)->value('workspace_id');
$paths = array_slice($argv, 3);
$n = (int) $argv[2];
$queries = 0;
DB::listen(function () use (&$queries) { $queries++; });
printf("%-60s %6s %8s %8s %8s %7s\n", 'endpoint', 'status', 'p50 ms', 'p95 ms', 'max ms', 'queries');
foreach ($paths as $p) {
    $url = str_replace('{ws}', $ws, $p);
    $times = []; $status = 0; $q = 0;
    for ($i = 0; $i < $n + 2; $i++) {
        $app['cache']->store()->flush(); $app['auth']->forgetGuards();
        $app['auth']->guard('web')->setUser($user);
        $req = Illuminate\Http\Request::create($url, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_REFERER' => 'http://localhost/', 'HTTP_ACCEPT_LANGUAGE' => 'vi']);
        $queries = 0;
        $t = hrtime(true);
        $res = $kernel->handle($req);
        $ms = (hrtime(true) - $t) / 1e6;
        $kernel->terminate($req, $res);
        if ($i >= 2) { $times[] = $ms; } // first 2 = warm-up
        $status = $res->getStatusCode(); $q = $queries; if ($status >= 500 && $i === 0) { fwrite(STDERR, substr((string) $res->getContent(), 0, 600)."\n"); }
        $app->forgetScopedInstances();
    }
    sort($times);
    $pct = fn ($x) => $times[min(count($times) - 1, (int) floor($x * (count($times) - 1)))];
    printf("%-60s %6d %8.1f %8.1f %8.1f %7d\n", substr($p, 0, 60), $status, $pct(0.5), $pct(0.95), end($times), $q);
}
