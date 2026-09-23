# Testing and quality checks

The plugin has four test suites and two static checks. All of them run in CI
on every push and pull request.

| Suite | What it covers | Runs in | Command |
|---|---|---|---|
| Unit | Classes in `src/` without WordPress | Your PHP | `composer test` |
| Unit, build | The same tests against the PHP-Scoper build | Your PHP | `composer test:build` |
| Integration | The plugin inside a real WordPress install | wp-env (Docker) | `npm run test:integration` |
| Integration, build | The same tests against the build, next to a conflicting `psr/log` | wp-env (Docker) | `npm run test:integration:build` |

## Requirements

- PHP 7.4 or later and Composer 2, for the unit tests, PHPCS and PHPStan.
- Node.js 20 or later and Docker, for the integration tests.
- PHP-Scoper, for the build suites: `composer global require humbug/php-scoper:^0.18`
  (PHP-Scoper itself needs PHP 8.1 or later).

Install the dependencies once:

```sh
composer install
npm install
```

## Command reference

| Command | Does |
|---|---|
| `composer qa` | `lint`, `stan` and `test` in one go. Run this before opening a pull request. |
| `composer lint` | PHPCS, errors only. This is what CI enforces. |
| `composer lint:strict` | PHPCS including warnings. |
| `composer lint:fix` | Fix what PHPCBF can fix automatically. |
| `composer stan` | PHPStan static analysis. |
| `composer stan:baseline` | Regenerate `phpstan-baseline.neon` (see below before you do). |
| `composer test` | Unit suite. |
| `composer test:coverage` | Unit suite with coverage: HTML in `coverage/`, Clover in `coverage.xml`. Needs pcov or Xdebug. |
| `composer build` | Build the scoped plugin into `build/cloudflare`. |
| `composer test:build` | Unit suite against `build/cloudflare`. Run `composer build` first. |
| `npm run env:test:start` / `env:test:stop` | Start or stop the integration test environment. |
| `npm run test:integration` | Integration suite. |
| `npm run env:build:start` / `env:build:stop` | Start or stop the build test environment. |
| `npm run test:integration:build` | Integration suite against `build/cloudflare`. |
| `npm run env:start` / `env:stop` | Start or stop the development site. |

## Unit tests

Unit tests live in `tests/Unit` and mirror `src/`, for example
`src/WordPress/Hooks.php` is tested by `tests/Unit/WordPress/HooksTest.php` in
the namespace `Cloudflare\APO\Tests\Unit\WordPress`.

WordPress is not loaded:

- WordPress functions are mocked with
  [php-mock](https://github.com/php-mock/php-mock-phpunit) in the namespace of
  the code under test, for example
  `$this->getFunctionMock('Cloudflare\APO\WordPress', 'get_permalink')`.
- The few WordPress classes the code needs (`WP_Post`, `WP_Taxonomy`) are
  stubbed in `tests/Fixtures/WordPressClassStubs.php`, with the same
  constructor signatures as WordPress.

The suite is strict: a test fails when it prints output or when PHP reports a
deprecation.

`composer test:build` copies `tests/Unit` to `build/tests`, prefixes the
vendor namespaces the same way the build does, and runs the copy against the
scoped classes in `build/cloudflare`.

## Integration tests

Integration tests live in `tests/Integration` and run inside the wp-env test
environment, a real WordPress install with the plugin active in
`wp-content/plugins/cloudflare`.

```sh
npm run env:test:start
npm run test:integration
```

The test site is available at http://localhost:8879 while it runs.

WordPress is loaded the way `wp-admin/admin-ajax.php` loads it, so the
plugin's admin and AJAX hooks are registered and the WordPress admin APIs are
available.

### Writing integration tests

Extend `IntegrationTestCase`. Every test starts with:

- Cloudflare credentials stored for the site's domain. The key and zone ID are
  the documented Cloudflare API examples, not real credentials.
- An `HttpRecorder` in front of all HTTP requests made through the WordPress
  HTTP API. Requests to the Cloudflare API are recorded and answered with the
  responses you register; requests to any other host are blocked, so the suite
  never uses the network.
- `wp_die()` turned into a `WpDieException`, so code that ends a request can be
  tested.

Useful helpers: `createPost()`, `createUser()` (both cleaned up after the
test), `setPluginSetting()`, `respondWithZone()`, `pluginHooks()` (the plugin's
`Hooks` instance) and `hookPriority()`.

```php
$this->http->respondTo('GET', 'zones/' . self::ZONE_ID . '/settings/always_use_https', HttpRecorder::success(array(
    'id' => 'always_use_https',
    'value' => 'off',
)));

// ... run the code under test ...

$requests = $this->http->requestsTo('GET', 'zones/' . self::ZONE_ID . '/settings/always_use_https');
```

Cache purge tests extend `PurgeTestCase`, which switches on APO and answers
every API call a purge makes. `purgedUrls()` returns the URLs sent to
Cloudflare.

Some behaviour needs special handling:

- **wp-config.php constants** such as `CLOUDFLARE_API_KEY` cannot be undefined
  again, so tests that define them run in separate PHP processes
  (`@runTestsInSeparateProcesses`), see `CredentialConstantsTest`.
- **Response headers** cannot be read from inside PHPUnit.
  `ResponseHeadersTest` requests the site over HTTP from the wp-env `wordpress`
  container instead.
- **Features switched on by constants**, such as HTTP/2 server push, are
  switched on per request by the must-use plugin
  `tests/Fixtures/MuPlugins/TestRequestToggles.php`, for example with
  `?cloudflare_test_http2_push=1`.

### Against the build

The build environment mounts `build/` as the plugins folder, so the scoped
plugin is tested exactly as it ships. Its must-use plugin
`tests/Fixtures/MuPlugins/ConflictingPsrLog.php` first loads an unprefixed
`Psr\Log\LoggerInterface` with the return types psr/log 3 added, the way
another plugin bundling psr/log would. The unprefixed source plugin cannot load
next to it; the build must.

```sh
composer build
npm run env:build:start
npm run test:integration:build
```

The build site runs at http://localhost:8877. `ScopedDependenciesTest` only
runs in this environment and is skipped elsewhere. You can rebuild while the
environment is running.

### Other WordPress and PHP versions

The environments use the latest WordPress on PHP 7.4. To test another
combination, set `WP_ENV_CORE` and `WP_ENV_PHP_VERSION` when starting and
running:

```sh
export WP_ENV_CORE=https://wordpress.org/wordpress-6.7.9.zip WP_ENV_PHP_VERSION=8.3
npm run env:test:start
npm run test:integration
```

Start again without the variables to go back to the defaults.

### Troubleshooting

- **"The Cloudflare plugin is not active in this WordPress install."** A folder
  the environment mounts was deleted and recreated after it started, usually
  by switching to a branch without `tests/Fixtures/MuPlugins`. Docker keeps
  showing the deleted folder, which is empty. Restart the environment:
  `npm run env:test:stop` and then `npm run env:test:start`.
- **A port is already in use.** The environments use ports 8878 (development),
  8879 (tests) and 8877 (build). Set `WP_ENV_PORT` to use another one.
- **A test failed with a PHP fatal error.** The environments hide PHP errors
  from web requests (`WP_DEBUG_DISPLAY` is off), but the integration suite
  shows them on stderr. Web requests log to `wp-content/debug.log` inside the
  container.

## Coding standards

`phpcs.xml.dist` combines:

- PSR-12, the code style of this plugin: PascalCase classes and file names,
  camelCase methods, four spaces.
- PHPCompatibilityWP, for PHP 7.4 and later.
- The WordPress sniffs that find security and correctness problems:
  escaping output, sanitizing input, nonces, database queries, internationalization
  and a few discouraged functions.

If a finding is a false positive, add a `phpcs:ignore` comment for that sniff
with the reason, for example
`// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a file bundled with the plugin, not a remote URL.`

## Static analysis

PHPStan runs at level 5 on `src`, `deprecated`, the tests and the plugin
entry files, for PHP 7.4.

`phpstan-baseline.neon` is empty and should stay that way. Only add an entry
for an error that cannot be fixed, such as a gap in the WordPress stubs, and
put a `# BASELINE: <reason>` comment above it. On pull requests CI fails when
the baseline grows without such comments.

## Supported versions

- **PHP:** 7.4 and later. The unit tests run on PHP 7.4 to 8.4, with and
  without the intl extension.
- **WordPress:** the five newest major versions. CI reads the current releases
  from the WordPress.org API and runs the integration suite on the latest
  release of each of them with PHP 7.4, and on the newest release with PHP 8.4.

When a new WordPress major version is released, the "Integration Tests"
workflow shows a warning on `readme.txt`. Raise the minimum to the oldest of
the five newest majors in:

- `readme.txt`: `Requires at least`
- `cloudflare.php`: the `Requires at least` header and `CLOUDFLARE_MIN_WP_VERSION`
- `phpcs.xml.dist`: `minimum_supported_wp_version`

## Continuous integration

| Workflow | Jobs |
|---|---|
| Test PHP Source | Composer validation, PHP syntax check and unit tests on PHP 7.4 to 8.4 (with and without intl), PHPCS, and coverage on PHP 8.3 (downloadable as the `coverage` artifact) |
| PHPStan | PHPStan, and on pull requests the baseline check |
| Integration Tests | The integration suite on the five newest WordPress majors |
| Test Build Artifact | Build with PHP-Scoper, then the syntax check, the unit tests and the integration suite against the build |
