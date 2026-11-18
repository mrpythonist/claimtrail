<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Permission;
use App\Services\HealthCheckService;
use App\Services\QueueMonitorService;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Health endpoints.
 *
 * The public endpoint answers load balancers and uptime monitors:
 * it reports only whether the app and its dependencies are up,
 * with no internal detail. The operations endpoint is for org
 * admins and adds version info and queue totals.
 */
class HealthController extends Controller
{
    /**
     * Public health check. 200 when ok or degraded, 503 when down.
     */
    public function show(HealthCheckService $health): JsonResponse
    {
        $result = $health->run();

        return response()->json($result, $result['status'] === 'down' ? 503 : 200);
    }

    /**
     * Detailed health for organization admins.
     */
    public function ops(Request $request, HealthCheckService $health, QueueMonitorService $queues): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewOperations->value, $organization);

        $result = $health->run();
        $stats = $queues->stats();

        return response()->json([
            'data' => array_merge($result, [
                'version' => config('app.version'),
                'php_version' => PHP_VERSION,
                'laravel_version' => Application::VERSION,
                'queue_totals' => $stats['totals'],
                'queue_supported' => $stats['supported'],
            ]),
        ]);
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
