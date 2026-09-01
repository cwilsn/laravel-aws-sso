<?php

declare(strict_types=1);

namespace LaravelAwsSso\Exceptions;

use Throwable;

/**
 * Marker interface implemented by every exception this package throws.
 *
 * Callers can catch this to present a package failure to the developer
 * without accidentally swallowing unrelated errors.
 */
interface LaravelAwsSsoException extends Throwable {}
