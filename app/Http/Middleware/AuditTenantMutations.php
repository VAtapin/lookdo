<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\AuditService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuditTenantMutations
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $tenant = $request->route('tenant');

        if ($request->isMethodSafe()
            || $response->getStatusCode() >= 400
            || (bool) $request->attributes->get('audit_recorded')
            || ! $request->user()
            || ! $tenant instanceof Tenant) {
            return $response;
        }

        $route = $request->route();
        $actionName = $route?->getActionName() ?: 'unknown@unknown';
        [$controller, $method] = array_pad(explode('@', $actionName, 2), 2, 'unknown');
        $area = Str::of(class_basename($controller))
            ->beforeLast('Controller')
            ->after('Tenant')
            ->snake()
            ->replace('_', '.')
            ->value() ?: 'account';
        $parameters = collect($route?->parameters() ?? [])
            ->except('tenant')
            ->map(fn ($value) => $value instanceof Model ? $value->getKey() : $value)
            ->all();

        $this->audit->log(
            'workspace.'.$area.'.'.Str::snake($method),
            $tenant,
            null,
            ['http_method' => $request->method(), 'route' => $route?->uri(), 'parameters' => $parameters],
            $tenant->id,
        );

        return $response;
    }
}
