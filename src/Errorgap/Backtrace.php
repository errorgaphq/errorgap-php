<?php

declare(strict_types=1);

namespace Errorgap;

final class Backtrace
{
    /**
     * @return list<array{file: ?string, line: ?int, function: ?string, in_app: bool, index: int}>
     */
    public static function fromThrowable(\Throwable $exception, string $rootDirectory): array
    {
        $frames = [];
        $index = 0;
        foreach ($exception->getTrace() as $frame) {
            /** @var array<string, mixed> $frame */
            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : null;
            $line = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : null;
            $function = self::formatFunction($frame);

            $frames[] = [
                'file' => self::relative($file, $rootDirectory),
                'line' => $line,
                'function' => $function,
                'in_app' => self::isInApp($file, $rootDirectory),
                'index' => $index++,
            ];
        }

        return $frames;
    }

    /**
     * @param array<string, mixed> $frame
     */
    private static function formatFunction(array $frame): ?string
    {
        $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : null;
        $type = isset($frame['type']) && is_string($frame['type']) ? $frame['type'] : null;
        $function = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : null;

        if ($function === null) {
            return null;
        }

        if ($class !== null && $type !== null) {
            return $class . $type . $function;
        }

        return $function;
    }

    private static function relative(?string $file, string $root): ?string
    {
        if ($file === null || $root === '') {
            return $file;
        }
        $normalized = str_ends_with($root, DIRECTORY_SEPARATOR) ? $root : $root . DIRECTORY_SEPARATOR;
        if (str_starts_with($file, $normalized)) {
            return substr($file, strlen($normalized));
        }
        return $file;
    }

    private static function isInApp(?string $file, string $root): bool
    {
        if ($file === null || $root === '') {
            return false;
        }
        if (str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
            return false;
        }
        return str_starts_with($file, $root);
    }
}
