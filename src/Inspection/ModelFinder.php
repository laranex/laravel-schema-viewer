<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Inspection;

use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Finds the Eloquent models declared in a set of folders.
 */
final class ModelFinder
{
    /**
     * @param  list<string>  $paths
     * @return list<class-string<Model>>
     */
    public function find(array $paths): array
    {
        $models = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                foreach ($this->classesIn((string) file_get_contents($file->getPathname())) as $class) {
                    if ($this->isModel($class)) {
                        $models[$class] = true;
                    }
                }
            }
        }

        $models = array_keys($models);
        sort($models);

        return $models;
    }

    /**
     * The fully qualified classes a PHP file declares, read from its tokens without loading it.
     *
     * @return list<string>
     */
    public function classesIn(string $code): array
    {
        $tokens = token_get_all($code);
        $namespace = '';
        $classes = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i])) {
                continue;
            }

            if ($tokens[$i][0] === T_NAMESPACE) {
                $namespace = '';

                for ($j = $i + 1; $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace .= $tokens[$j][1];
                    }
                }
            }

            if ($tokens[$i][0] === T_CLASS && ! $this->isClassConstantOrAnonymous($tokens, $i)) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $classes[] = ltrim($namespace.'\\'.$tokens[$j][1], '\\');

                        break;
                    }
                }
            }
        }

        return $classes;
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    private function isClassConstantOrAnonymous(array $tokens, int $index): bool
    {
        for ($j = $index - 1; $j >= 0; $j--) {
            $token = $tokens[$j];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) && in_array($token[0], [T_DOUBLE_COLON, T_NEW], true);
        }

        return false;
    }

    /**
     * @phpstan-assert-if-true class-string<Model> $class
     */
    private function isModel(string $class): bool
    {
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        return $reflection->isInstantiable() && ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0);
    }
}
