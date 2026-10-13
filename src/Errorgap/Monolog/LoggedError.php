<?php

declare(strict_types=1);

namespace Errorgap\Monolog;

/** An error-level log record that had no exception attached. */
final class LoggedError extends \RuntimeException
{
}
