<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Contract;

use InvalidArgumentException;

/**
 * Not a laranex-schema 2.x document: another format, or another major version, 1.x included.
 */
final class UnsupportedDocument extends InvalidArgumentException {}
