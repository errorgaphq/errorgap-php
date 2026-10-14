<?php

declare(strict_types=1);

namespace Errorgap;

final class Filter
{
    public const FILTERED = '[FILTERED]';
    public const TRUNCATED = '[TRUNCATED]';
    private const MAX_STRING = 4096;
    private const MAX_ITEMS = 128;
    private const MAX_DEPTH = 8;

    /**
     * Mask sensitive keys at any depth (inside lists too) and make the value
     * safe to serialize: long strings and large or deeply nested arrays are
     * cut, objects and resources become strings.
     *
     * @param array<array-key, mixed> $params
     * @param list<string> $filterKeys
     * @return array<array-key, mixed>
     */
    public static function params(array $params, array $filterKeys): array
    {
        $lowered = array_map(static fn (string $k): string => strtolower($k), $filterKeys);
        return self::walk($params, $lowered, 0);
    }

    /**
     * Truncate without masking anything.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    public static function clean(array $value): array
    {
        return self::walk($value, null, 0);
    }

    public static function string(string $value, int $limit = self::MAX_STRING): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }
        // Cut on a byte boundary, then drop a split multi-byte character.
        $cut = substr($value, 0, $limit);
        if (function_exists('mb_strcut')) {
            $cut = mb_strcut($value, 0, $limit, 'UTF-8');
        }
        return $cut . self::TRUNCATED;
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string>|null $lowered
     * @return array<array-key, mixed>
     */
    private static function walk(array $value, ?array $lowered, int $depth): array
    {
        $out = [];
        $count = 0;
        foreach ($value as $key => $val) {
            if ($count++ >= self::MAX_ITEMS) {
                break;
            }
            if ($lowered !== null && is_string($key) && self::isSensitive($key, $lowered)) {
                $out[$key] = self::FILTERED;
            } elseif (is_array($val)) {
                $out[$key] = $depth + 1 >= self::MAX_DEPTH ? self::TRUNCATED : self::walk($val, $lowered, $depth + 1);
            } elseif (is_string($val)) {
                $out[$key] = self::string($val);
            } elseif (is_scalar($val) || $val === null) {
                $out[$key] = is_float($val) && !is_finite($val) ? (string)$val : $val;
            } elseif ($val instanceof \JsonSerializable || $val instanceof \stdClass) {
                $encoded = json_decode((string)json_encode($val, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
                $out[$key] = is_array($encoded)
                    ? ($depth + 1 >= self::MAX_DEPTH ? self::TRUNCATED : self::walk($encoded, $lowered, $depth + 1))
                    : $encoded;
            } elseif ($val instanceof \Stringable) {
                $out[$key] = self::string((string)$val);
            } else {
                $out[$key] = is_object($val) ? '[' . $val::class . ']' : '[' . gettype($val) . ']';
            }
        }
        return $out;
    }

    /**
     * @param list<string> $lowered
     */
    private static function isSensitive(string $key, array $lowered): bool
    {
        $k = strtolower($key);
        foreach ($lowered as $needle) {
            if ($needle !== '' && str_contains($k, $needle)) {
                return true;
            }
        }
        return false;
    }
}
