<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lets everyone in locally, which is anyone who can reach the app (Herd Share and tunnels included); elsewhere only
 * users the viewSchema gate allows.
 */
final class Authorize
{
    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::allows($this->app, $request)) {
            throw new AccessDeniedHttpException('Define the viewSchema gate to open the schema viewer outside the local environment.');
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    public static function allows(Application $app, Request $request): bool
    {
        if ($app->environment('local')) {
            return true;
        }

        if (! $app->bound(Gate::class)) {
            return false;
        }

        $gate = $app->make(Gate::class);

        return $gate->has('viewSchema') && $gate->forUser($request->user())->allows('viewSchema');
    }
}
