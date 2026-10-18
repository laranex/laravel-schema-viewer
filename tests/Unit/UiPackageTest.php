<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Laranex\LaravelSchemaViewer\Scripts\UiPackage;

/**
 * A ustar member: a 512-byte header with a valid checksum, then the content padded to 512 bytes.
 */
function tarMember(string $name, string $content = 'x', string $type = '0', string $link = ''): string
{
    $header = str_pad($name, 100, "\0")
        .str_pad('0000644', 8, "\0").str_pad('0000000', 8, "\0").str_pad('0000000', 8, "\0")
        .str_pad(decoct(strlen($content)), 11, '0', STR_PAD_LEFT)."\0"
        .str_pad('0', 11, '0', STR_PAD_LEFT)."\0"
        .'        '.$type.str_pad($link, 100, "\0")."ustar\0".'00'.str_repeat("\0", 247);
    $sum = array_sum(array_map('ord', str_split($header)));
    $header = substr_replace($header, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8);

    return $header.str_pad($content, (int) (ceil(strlen($content) / 512) * 512), "\0");
}

/**
 * @param  list<string>  $members
 */
function tarball(array $members): string
{
    return (string) gzencode(implode('', $members).str_repeat("\0", 1024));
}

/**
 * A scratch repository root holding the lock, the stamp and the installed assets of this repository.
 */
function scratchRoot(): string
{
    $root = sys_get_temp_dir().'/schema-viewer-ui-'.bin2hex(random_bytes(6));
    register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($root));
    $source = dirname(__DIR__, 2);

    foreach ([UiPackage::LOCK, UiPackage::STAMP, ...array_keys(json_decode((string) file_get_contents($source.'/'.UiPackage::STAMP), true)['files'])] as $path) {
        @mkdir(dirname($root.'/'.$path), 0755, true);
        copy($source.'/'.$path, $root.'/'.$path);
    }

    return $root;
}

it('installs exactly the verified assets the stamp lists, matching the lock and the viewer manifest', function () {
    // This repository's committed assets: a release fails here when one is missing, modified or unverified.
    expect((new UiPackage(dirname(__DIR__, 2)))->check())->toBe([]);

    $stamp = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/'.UiPackage::STAMP), true);
    $lock = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/'.UiPackage::LOCK), true);

    expect($stamp)->toMatchArray(['name' => $lock['name'], 'version' => $lock['version'], 'integrity' => $lock['integrity'], 'source' => $lock['tarball'], 'verified' => true])
        ->and(array_keys($stamp['files']))->toContain('resources/viewer/app.js', 'resources/viewer/app.css', 'resources/viewer/offline.js', 'resources/viewer/favicon.svg', 'resources/viewer/favicon.ico', 'resources/viewer/NOTICE', 'resources/contract/schema.json');
});

it('fails the check when an asset is modified, missing, unlisted or unverified', function (Closure $tamper, string $problem) {
    $root = scratchRoot();
    $tamper($root);

    expect(implode("\n", (new UiPackage($root))->check()))->toContain($problem);
})->with([
    'modified' => [fn (string $root) => file_put_contents($root.'/resources/viewer/app.js', '/* changed */', FILE_APPEND), 'Modified asset resources/viewer/app.js'],
    'missing' => [fn (string $root) => unlink($root.'/resources/viewer/app.css'), 'Missing asset resources/viewer/app.css'],
    'unlisted' => [fn (string $root) => file_put_contents($root.'/resources/viewer/extra.js', 'alert(1)'), 'Unverified file resources/viewer/extra.js'],
    'unverified' => [function (string $root) {
        $stamp = json_decode((string) file_get_contents($root.'/'.UiPackage::STAMP), true);
        file_put_contents($root.'/'.UiPackage::STAMP, json_encode([...$stamp, 'verified' => false]));
    }, 'records unverified assets'],
    'another version' => [function (string $root) {
        $lock = json_decode((string) file_get_contents($root.'/'.UiPackage::LOCK), true);
        file_put_contents($root.'/'.UiPackage::LOCK, json_encode([...$lock, 'version' => '0.3.1']));
    }, 'version is 0.3.0, ui.lock.json pins 0.3.1'],
    'restamped edit' => [function (string $root) {
        // Editing a file and its stamp entry together still fails against the viewer manifest.
        file_put_contents($root.'/resources/viewer/app.js', '/* changed */');
        $stamp = json_decode((string) file_get_contents($root.'/'.UiPackage::STAMP), true);
        $stamp['files']['resources/viewer/app.js'] = hash('sha256', '/* changed */');
        file_put_contents($root.'/'.UiPackage::STAMP, json_encode($stamp));
    }, 'resources/viewer/app.js does not match the viewer manifest'],
    'no stamp' => [fn (string $root) => unlink($root.'/'.UiPackage::STAMP), 'ui.stamp.json is missing or malformed'],
]);

it('reads only regular files under package/ from a tarball', function () {
    expect(UiPackage::members(tarball([tarMember('package/a.txt', 'a'), tarMember('package/dist/b.js', 'b')])))
        ->toBe(['a.txt' => 'a', 'dist/b.js' => 'b']);
});

it('refuses unsafe tarball members', function (string $tarball, string $message) {
    expect(fn () => UiPackage::members($tarball))->toThrow(RuntimeException::class, $message);
})->with([
    'parent path' => [fn () => tarball([tarMember('package/../evil.php')]), 'Unsafe tarball member'],
    'absolute path' => [fn () => tarball([tarMember('/etc/passwd')]), 'Unsafe tarball member'],
    'outside package/' => [fn () => tarball([tarMember('other/a.txt')]), 'Unsafe tarball member'],
    'symlink' => [fn () => tarball([tarMember('package/link', '', '2', '/etc/passwd')]), 'Unsafe tarball member'],
    'hard link' => [fn () => tarball([tarMember('package/link', '', '1', 'package/a.txt')]), 'Unsafe tarball member'],
    'directory' => [fn () => tarball([tarMember('package/dir/', '', '5')]), 'Unsafe tarball member'],
    'extended header' => [fn () => tarball([tarMember('package/PaxHeader', "20 path=package/x\n", 'x')]), 'Unsafe tarball member'],
    'backslash' => [fn () => tarball([tarMember('package\\..\\evil.php')]), 'Unsafe tarball member'],
    'duplicate' => [fn () => tarball([tarMember('package/a.txt'), tarMember('package/a.txt')]), 'Duplicate tarball member'],
    'damaged header' => [fn () => (string) gzencode(substr_replace(tarMember('package/a.txt'), 'Z', 10, 1).str_repeat("\0", 1024)), 'damaged or non-ustar header'],
    'not gzip' => [fn () => 'plain', 'not gzip data'],
    'truncated' => [fn () => (string) gzencode(substr(tarMember('package/a.txt', str_repeat('a', 2000)), 0, 1024)), 'truncated'],
]);

it('refuses a tarball whose integrity differs from the lock, unless explicitly allowed and stamped unverified', function () {
    $root = scratchRoot();
    $other = $root.'/other.tgz';
    file_put_contents($other, tarball([tarMember('package/package.json', '{}')]));

    expect(fn () => (new UiPackage($root))->fetch($other))->toThrow(RuntimeException::class, 'does not match ui.lock.json')
        // Nothing was replaced.
        ->and((new UiPackage($root))->check())->toBe([])
        // Allowed, a tarball that is not the package is still refused before anything is installed.
        ->and(fn () => (new UiPackage($root))->fetch($other, true))->toThrow(RuntimeException::class, 'Not a laranex-schema-viewer-ui package')
        ->and((new UiPackage($root))->check())->toBe([]);
});

it('installs a local copy of the locked tarball, and stamps a modified build unverified', function () {
    $contract = contractFiles();
    $root = scratchRoot();
    $cached = dirname(__DIR__, 2).'/'.UiPackage::CACHE.'/laranex-schema-viewer-ui-0.3.0.tgz';

    expect((new UiPackage($root))->fetch($cached)['verified'])->toBeTrue()
        ->and((new UiPackage($root))->check())->toBe([])
        ->and($contract)->toHaveKey('schema.json');

    // The same package with one byte of NOTICE changed: a different integrity.
    $members = UiPackage::members((string) file_get_contents($cached));
    $members['NOTICE'] .= ' ';
    $modified = $root.'/modified.tgz';
    file_put_contents($modified, tarball(array_map(fn (string $name, string $content): string => tarMember('package/'.$name, $content), array_keys($members), $members)));

    $stamp = (new UiPackage($root))->fetch($modified, true);

    expect($stamp['verified'])->toBeFalse()
        ->and($stamp['source'])->toBe('file:modified.tgz')
        ->and(implode("\n", (new UiPackage($root))->check()))->toContain('records unverified assets');
});
