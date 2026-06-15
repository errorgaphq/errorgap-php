<?php

declare(strict_types=1);

namespace Errorgap;

final class Filter
{
    public const FILTERED = '[FILTERED]';

    /**
     * @param array<string, mixed> $params
     * @param list<string> $filterKeys
     * @return array<string, mixed>
     */
    public static function params(array $params, array $filterKeys): array
    {
        $lowered = array_map(static fn (string $k): string => strtolower($k), $filterKeys);
        return self::walk($params, $lowered);
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $lowered
     * @return array<string, mixed>
     */
    private static function walk(array $value, array $lowered): array
    {
        $out = [];
        foreach ($value as $key => $val) {
            if (self::isSensitive((string)$key, $lowered)) {
                $out[$key] = self::FILTERED;
            } elseif (is_array($val) && self::isAssoc($val)) {
                /** @var array<string, mixed> $val */
                $out[$key] = self::walk($val, $lowered);
            } else {
                $out[$key] = $val;
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
            if (str_contains($k, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<mixed> $arr
     */
    private static function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
