<?php

declare(strict_types=1);

namespace Pollora\Asset\Infrastructure\Repositories;

use Pollora\Asset\Domain\Models\AssetContainer;

/*
 * @deprecated Use Pollora\Asset\Domain\Models\AssetContainer.
 * This file only keeps the former class name autoloadable: loading the new
 * class declares the alias. It is removed in the next major version.
 */
class_exists(AssetContainer::class);
