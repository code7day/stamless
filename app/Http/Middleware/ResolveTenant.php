<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\TenantManager;
use App\Models\Tenant;

class ResolveTenant
{
    protected TenantManager $tenantManager;

    public function __construct(TenantManager $tenantManager)
    {
        $this->tenantManager = $tenantManager;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = null;

        try {
            // 1. Resolve by query parameter or header (convenient for API testing/dev)
            if ($request->has('tenant')) {
                $tenant = Tenant::where('slug', $request->query('tenant'))->first();
            } elseif ($request->headers->has('X-Tenant-Slug')) {
                $tenant = Tenant::where('slug', $request->header('X-Tenant-Slug'))->first();
            } elseif ($request->headers->has('X-Tenant-Id')) {
                $tenant = Tenant::find($request->header('X-Tenant-Id'));
            }

            // 2. Resolve by domain/hostname
            if (!$tenant) {
                $host = $request->getHost();
                $tenant = Tenant::whereHas('domains', function ($query) use ($host) {
                    $query->where('domain', $host);
                })->first();
            }
        } catch (\Throwable $e) {
            // Fallback if the database/tables do not exist yet (e.g., during migrations or testing)
            $tenant = null;
        }

        // 3. Set the resolved tenant in the manager
        if ($tenant) {
            $this->tenantManager->setTenant($tenant);
        }

        return $next($request);
    }
}
