<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Http\Controllers;

use Laranex\LaravelSchemaViewer\Viewer\ViewerAssets;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the shared viewer from the package, so there is nothing to publish and nothing to go stale: exactly the files
 * its manifest lists, at the size the manifest gives, under the bundle's fingerprint, which lets browsers keep them.
 * The viewer's own index.html is never served; the viewer page replaces it.
 */
final class AssetController
{
    private const TYPES = [
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ];

    public function __invoke(ViewerAssets $assets, string $version, string $file): BinaryFileResponse
    {
        $type = self::TYPES[pathinfo($file, PATHINFO_EXTENSION)] ?? null;
        $path = $version === $assets->version() && $file !== $assets->entry('page') ? $assets->path($file) : null;

        if ($path === null || $type === null) {
            throw new NotFoundHttpException('', null, 0, ['Cache-Control' => 'no-store']);
        }

        return new BinaryFileResponse($path, 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ], public: false, autoEtag: false, autoLastModified: false);
    }
}
