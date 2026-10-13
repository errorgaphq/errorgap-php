<?php

declare(strict_types=1);

namespace Errorgap;

/**
 * Works out which commit is running when `release` is not configured, so
 * errors are attributed to a deploy without any setup: the commit variables
 * hosting platforms export, a Capistrano/Deployer REVISION file, then the
 * checkout's `.git/HEAD`.
 */
final class Release
{
    private const ENV_KEYS = [
        'ERRORGAP_RELEASE', 'SOURCE_VERSION', 'HEROKU_SLUG_COMMIT', 'HEROKU_BUILD_COMMIT',
        'RENDER_GIT_COMMIT', 'RAILWAY_GIT_COMMIT_SHA', 'VERCEL_GIT_COMMIT_SHA', 'KAMAL_VERSION',
        'GIT_COMMIT', 'GIT_SHA',
    ];

    public static function detect(string $rootDirectory): ?string
    {
        foreach (self::ENV_KEYS as $key) {
            $value = getenv($key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        if ($rootDirectory === '') {
            return null;
        }
        $revision = self::read($rootDirectory . '/REVISION');
        if ($revision !== null && $revision !== '') {
            return $revision;
        }
        $git = $rootDirectory . '/.git';
        $head = self::read($git . '/HEAD');
        if ($head === null) {
            return null;
        }
        if (preg_match('/^ref:\s*(.+)$/', $head, $match) !== 1) {
            return self::sha($head);
        }
        $ref = trim($match[1]);
        $loose = self::read($git . '/' . $ref);
        if ($loose !== null) {
            return self::sha($loose);
        }
        foreach (explode("\n", self::read($git . '/packed-refs') ?? '') as $line) {
            if (str_ends_with(trim($line), ' ' . $ref)) {
                return self::sha(explode(' ', trim($line))[0]);
            }
        }
        return null;
    }

    private static function read(string $path): ?string
    {
        if (!@is_file($path) || !@is_readable($path)) {
            return null;
        }
        $content = @file_get_contents($path, false, null, 0, 65536);
        return $content === false ? null : trim($content);
    }

    private static function sha(string $value): ?string
    {
        return preg_match('/^[0-9a-f]{7,64}$/i', $value) === 1 ? $value : null;
    }
}
