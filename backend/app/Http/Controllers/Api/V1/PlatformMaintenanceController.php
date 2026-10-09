<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;

/**
 * Cache and housekeeping tools for the platform operator.
 *
 * After a deploy or a settings change the usual fixes — clear the cache,
 * the compiled views, the cached config or routes, restart the queue
 * workers — needed SSH. These are the same artisan commands, run from the
 * console, each one audited with who ran it and what it printed.
 *
 * What clearing costs: the application cache also holds rate-limit counters
 * and short-lived codes, which start again from zero; it is a separate Redis
 * database from the queue, so queued jobs are not touched. Clearing the
 * config or route cache makes the next requests read the files again until
 * "Rebuild caches" runs, which is slower but correct.
 */
class PlatformMaintenanceController extends Controller
{
    /** task => [artisan command, what it does] */
    public const TASKS = [
        'cache' => ['cache:clear', 'Clear the application cache'],
        'views' => ['view:clear', 'Clear compiled views'],
        'config' => ['config:clear', 'Clear the cached configuration'],
        'routes' => ['route:clear', 'Clear the cached routes'],
        'events' => ['event:clear', 'Clear the cached events'],
        'all' => ['optimize:clear', 'Clear every cache at once'],
        'rebuild' => ['optimize', 'Rebuild the config, route, view and event caches'],
        'queue' => ['queue:restart', 'Restart the queue workers after they finish their current job'],
    ];

    public function status(): JsonResponse
    {
        $views = File::isDirectory(storage_path('framework/views'))
            ? count(File::glob(storage_path('framework/views/*.php')))
            : 0;

        return response()->json([
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'release' => basename(realpath(dirname(base_path())) ?: dirname(base_path())),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'drivers' => [
                'cache' => config('cache.default'),
                'queue' => config('queue.default'),
                'session' => config('session.driver'),
            ],
            'cached' => [
                'config' => app()->configurationIsCached(),
                'routes' => app()->routesAreCached(),
                'events' => app()->eventsAreCached(),
                'compiled_views' => $views,
            ],
            'tasks' => collect(self::TASKS)->map(fn ($t, $key) => ['task' => $key, 'label' => $t[1]])->values(),
            'recent' => AuditLog::withoutGlobalScopes()->where('action', 'like', 'platform.maintenance.%')
                ->with('user:id,name')->latest('id')->limit(10)->get()
                ->map(fn ($row) => [
                    'task' => substr($row->action, strlen('platform.maintenance.')),
                    'by' => $row->user?->name,
                    'at' => $row->created_at?->toIso8601String(),
                    'ok' => (bool) ($row->new_values['ok'] ?? false),
                ]),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $data = $request->validate(['task' => ['required', Rule::in(array_keys(self::TASKS))]]);
        [$command, $label] = self::TASKS[$data['task']];

        try {
            $code = Artisan::call($command);
            $output = trim(Artisan::output());
        } catch (\Throwable $e) {
            $code = 1;
            $output = $e->getMessage();
        }

        $ok = $code === 0;

        AuditLog::create([
            'school_id' => null,
            'user_id' => $request->user()->id,
            'action' => 'platform.maintenance.'.$data['task'],
            'auditable_type' => 'platform',
            'auditable_id' => 0,
            'old_values' => null,
            'new_values' => ['command' => $command, 'ok' => $ok, 'output' => mb_substr($output, 0, 2000)],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => $ok ? "{$label}: done." : "{$label} failed.",
            'ok' => $ok,
            'output' => $output,
        ], $ok ? 200 : 500);
    }
}
