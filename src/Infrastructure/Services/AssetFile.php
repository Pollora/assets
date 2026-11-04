<?php

declare(strict_types=1);

namespace Pollora\Asset\Infrastructure\Services;

use Pollora\Asset\Application\Services\AssetFile;

/*
 * @deprecated Use Pollora\Asset\Application\Services\AssetFile.
 * This file only keeps the former class name autoloadable: loading the new
 * class declares the alias. It is removed in the next major version.
 */
class_exists(AssetFile::class);
