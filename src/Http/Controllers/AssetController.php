<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the built viewer from the package, so there is nothing to publish and nothing to go stale.
 */
final class AssetController
{
    private const TYPES = [
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'svg' => 'image/svg+xml',
    ];

    public function __invoke(string $file): BinaryFileResponse
    {
        $root = (string) realpath(__DIR__.'/../../../dist');
        $path = realpath($root.'/'.$file);
        $type = self::TYPES[pathinfo($file, PATHINFO_EXTENSION)] ?? null;

        if ($path === false || $type === null || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            throw new NotFoundHttpException;
        }

        return new BinaryFileResponse($path, 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
