# errorgap/errorgap

PHP notifier for [Errorgap](https://errorgap.com). Captures throwables,
normalizes backtraces, and ships notices to an Errorgap server. Use this
package for plain PHP, Symfony, Slim, or any framework other than WordPress
or Laravel (those have their own dedicated packages).

## Install

```sh
composer require errorgap/errorgap
```

Requires PHP 8.1+.

## Configure

```php
use Errorgap\Errorgap;

Errorgap::init([
    'endpoint' => $_ENV['ERRORGAP_ENDPOINT'],
    'projectSlug' => $_ENV['ERRORGAP_PROJECT_SLUG'],
    'apiKey' => $_ENV['ERRORGAP_API_KEY'],
    'environment' => $_ENV['APP_ENV'] ?? 'production',
]);
```

`Errorgap::init` reads the same values from `ERRORGAP_ENDPOINT`,
`ERRORGAP_PROJECT_SLUG`, `ERRORGAP_PROJECT_ID`, and `ERRORGAP_API_KEY` when
omitted. By default it installs `set_exception_handler` and
`set_error_handler`; pass `'captureGlobals' => false` to skip.

## Manual notification

```php
try {
    risky();
} catch (\Throwable $exc) {
    Errorgap::notify($exc, context: ['component' => 'billing']);
    throw $exc;
}
```

`notify` returns a `DeliveryResult` (`status`, `body`, `error`, `queued`).
The SDK never throws.

## Async delivery

By default, async mode schedules the HTTP call via `register_shutdown_function`
so the user's response is sent before the network call. Set
`'async' => false` for synchronous delivery (useful in tests, CLI scripts,
and long-running workers).

## Configuration reference

| Option | Default | Notes |
|---|---|---|
| `endpoint` | `ERRORGAP_ENDPOINT` or `http://127.0.0.1:3030` | Base URL, no trailing slash |
| `projectSlug` | `ERRORGAP_PROJECT_SLUG` | **Required** |
| `projectId` | `ERRORGAP_PROJECT_ID` | Optional, embedded in payload |
| `apiKey` | `ERRORGAP_API_KEY` | Sent as `x-errorgap-project-key` |
| `environment` | `ERRORGAP_ENVIRONMENT` or `production` | |
| `rootDirectory` | `getcwd()` | Used to mark frames as `in_app` |
| `async` | `true` | `register_shutdown_function` delivery |
| `logger` | `null` | PSR-3 logger; pass to receive SDK warnings |
| `filterKeys` | `['password', 'token', ...]` | Substring, case-insensitive |
| `timeoutSeconds` | `5` | HTTP request timeout |
| `captureGlobals` | `true` | Install error + exception handlers |

## Verify

```sh
curl -sS -X POST "$ERRORGAP_ENDPOINT/api/projects/$ERRORGAP_PROJECT_SLUG/notices" \
  -H "content-type: application/json" \
  -H "x-errorgap-project-key: $ERRORGAP_API_KEY" \
  -d '{"errors":[{"type":"ErrorgapInstallTest","message":"Errorgap install verification"}],"context":{"environment":"development"}}'
```

Then trigger a real error and confirm it appears in the Errorgap UI.

## Development

```sh
composer install
composer test
```

## License

MIT.
