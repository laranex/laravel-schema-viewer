<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lets in whoever SchemaViewer::check() allows: the SchemaViewer::auth() callback when the application sets one;
 * otherwise, in the local environment, requests from this machine to this machine, and any other request only for
 * users the viewSchema gate allows. Every route of the viewer has it, the page and every asset alike.
 */
final class Authorize
{
    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::allows($this->app, $request)) {
            // Nothing about the schema in the answer, and nothing a cache may keep.
            throw new AccessDeniedHttpException('Define the viewSchema gate, or SchemaViewer::auth(), to open the schema viewer.', null, 0, ['Cache-Control' => 'no-store']);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    public static function allows(Application $app, Request $request): bool
    {
        return SchemaViewer::check($request, $app);
    }
}
