<?php

declare(strict_types=1);

namespace Errorgap;

final class Backtrace
{
    private const SOURCE_CONTEXT_LINES = 6;
    private const MAX_SOURCE_LINE_LENGTH = 400;
    private const MAX_SOURCE_FILE_BYTES = 2_000_000;

    /**
     * @return list<array{
     *   file: ?string,
     *   line: ?int,
     *   function: ?string,
     *   in_app: bool,
     *   index: int,
     *   source?: array{start_line: int, lines: list<string>}
     * }>
     */
    public static function fromThrowable(\Throwable $exception, string $rootDirectory): array
    {
        $frames = [];
        $index = 0;
        $trace = $exception->getTrace();

        // Throwable::getTrace() starts at the caller of the throwing function.
        // Add the throwable's own file and line first so the dashboard points at
        // the actual throw statement and can render its source excerpt.
        $frames[] = self::frame(
            $exception->getFile(),
            $exception->getLine(),
            isset($trace[0]) ? self::formatFunction($trace[0]) : null,
            $rootDirectory,
            $index++,
        );

        foreach ($trace as $frame) {
            /** @var array<string, mixed> $frame */
            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : null;
            // The ingestion contract requires a string file path. PHP uses
            // file-less frames for internal functions such as array_map();
            // skip those frames while retaining their surrounding vendor calls.
            if ($file === null) {
                continue;
            }
            $line = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : null;
            $function = self::formatFunction($frame);

            $frames[] = self::frame($file, $line, $function, $rootDirectory, $index++);
        }

        return $frames;
    }

    /**
     * @return array{
     *   file: ?string,
     *   line: ?int,
     *   function: ?string,
     *   in_app: bool,
     *   index: int,
     *   source?: array{start_line: int, lines: list<string>}
     * }
     */
    private static function frame(
        ?string $file,
        ?int $line,
        ?string $function,
        string $rootDirectory,
        int $index,
    ): array {
        $frame = [
            'file' => self::relative($file, $rootDirectory),
            'line' => $line,
            'function' => $function,
            'in_app' => self::isInApp($file, $rootDirectory),
            'index' => $index,
        ];

        $source = self::sourceExcerpt($file, $line);
        if ($source !== null) {
            $frame['source'] = $source;
        }

        return $frame;
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

    /**
     * @return array{start_line: int, lines: list<string>}|null
     */
    private static function sourceExcerpt(?string $file, ?int $line): ?array
    {
        if ($file === null || $line === null || $line < 1 || !is_file($file) || !is_readable($file)) {
            return null;
        }

        $size = @filesize($file);
        if ($size === false || $size > self::MAX_SOURCE_FILE_BYTES) {
            return null;
        }

        $contents = @file($file, FILE_IGNORE_NEW_LINES);
        if ($contents === false) {
            return null;
        }

        $startLine = max(1, $line - self::SOURCE_CONTEXT_LINES);
        $length = (self::SOURCE_CONTEXT_LINES * 2) + 1;
        $excerpt = array_slice($contents, $startLine - 1, $length);
        if ($excerpt === []) {
            return null;
        }

        $lines = [];
        foreach ($excerpt as $sourceLine) {
            $sourceLine = substr($sourceLine, 0, self::MAX_SOURCE_LINE_LENGTH);
            $lines[] = preg_match('//u', $sourceLine) === 1 ? $sourceLine : '[invalid UTF-8]';
        }

        return ['start_line' => $startLine, 'lines' => $lines];
    }
}
