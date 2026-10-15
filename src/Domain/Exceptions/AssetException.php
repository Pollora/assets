<?php

declare(strict_types=1);

namespace Pollora\Asset\Domain\Exceptions;

use Exception;

/**
 * Domain exception for asset-related errors.
 *
 * This exception should be thrown for any domain-specific error encountered
 * during asset management, such as invalid configuration, missing files,
 * or unsupported asset types.
 */
class AssetException extends Exception {}
