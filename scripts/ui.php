<?php

declare(strict_types=1);

/*
 * Maintainer-only: install and check the shared viewer that ui.lock.json pins. Never run on composer install or in an
 * application; consumers get the verified files from the Composer distribution and never need Node.js, npm or the
 * network.
 *
 *   composer ui:fetch                                                 download the locked tarball and install it
 *   composer ui:fetch -- --tarball=ui.tgz                             a local copy of the locked tarball
 *   composer ui:fetch -- --tarball=ui.tgz --allow-unverified          a local build that does not match the lock
 *   composer ui:check                                                 stamp, lock, files and manifest agree
 *   composer ui:check -- --registry                                   also against the registry tarball
 *   composer ui:check -- --archive                                    also the git archive Composer installs
 */

use Laranex\LaravelSchemaViewer\Scripts\Archive;
use Laranex\LaravelSchemaViewer\Scripts\UiPackage;

require __DIR__.'/UiPackage.php';
require __DIR__.'/Archive.php';

$root = dirname(__DIR__);
$arguments = $_SERVER['argv'] ?? [];
$command = is_array($arguments) && is_string($arguments[1] ?? null) ? $arguments[1] : '';
$options = is_array($arguments) ? array_values(array_filter(array_slice($arguments, 2), 'is_string')) : [];
$tarball = null;

foreach ($options as $option) {
    if (str_starts_with($option, '--tarball=')) {
        $tarball = substr($option, strlen('--tarball='));
    } elseif (! in_array($option, ['--allow-unverified', '--registry', '--archive'], true)) {
        fwrite(STDERR, "ui: unknown option {$option}\n");
        exit(2);
    }
}

$package = new UiPackage($root);

try {
    if ($command === 'fetch') {
        if (in_array('--allow-unverified', $options, true) && $tarball === null) {
            fwrite(STDERR, "ui: --allow-unverified needs --tarball\n");
            exit(2);
        }

        $stamp = $package->fetch($tarball, in_array('--allow-unverified', $options, true));
        $state = $stamp['verified'] ? 'verified against '.UiPackage::LOCK : 'UNVERIFIED: it does not match '.UiPackage::LOCK.' and ui:check fails until composer ui:fetch installs the locked tarball';
        fwrite(STDOUT, sprintf("Installed %s %s (%d files, %s).\n", $stamp['name'], $stamp['version'], count($stamp['files']), $state));
        exit(0);
    }

    if ($command === 'check') {
        $problems = $package->check(in_array('--registry', $options, true));

        if ($problems === [] && in_array('--archive', $options, true)) {
            $problems = (new Archive($root))->check();
        }

        if ($problems !== []) {
            fwrite(STDERR, "ui: the shared viewer assets are not verified:\n  ".implode("\n  ", $problems)."\nRun composer ui:fetch, then commit resources/ and ".UiPackage::STAMP.".\n");
            exit(1);
        }

        fwrite(STDOUT, 'The shared viewer assets match '.UiPackage::LOCK.' and '.UiPackage::STAMP.".\n");
        exit(0);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ui: '.$e->getMessage()."\n");
    exit(1);
}

fwrite(STDERR, "usage: php scripts/ui.php fetch [--tarball=PATH [--allow-unverified]] | check [--registry] [--archive]\n");
exit(2);
