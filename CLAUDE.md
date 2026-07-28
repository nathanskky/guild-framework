# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Guild Framework (`guild/framework`) is a small IU-centric PHP framework/library — a thin composition layer over
existing PSR-friendly packages rather than a from-scratch framework. It is consumed as a Composer dependency by
downstream applications, not run standalone.

## Commands

```bash
composer install                  # install dependencies
vendor/bin/phpstan analyse        # static analysis (level 10, scoped to src/ — see phpstan.neon)
vendor/bin/phpunit                # run tests
```

There is no `phpunit.xml` yet, and `tests/` is currently empty — `phpunit.xml` will need to be added (or a
bootstrap/path passed to `vendor/bin/phpunit`) before tests can run. Test classes belong under the
`Guild\Framework\Test\` namespace (autoload-dev maps this to `tests/`).

## Architecture

The framework wires together several third-party libraries into one application container:
- **`league/container`** — the DI container (`Application` extends `League\Container\Container`)
- **`league/route`** — HTTP routing (`Router` extends `League\Route\Router`)
- **`laminas/laminas-diactoros`** + **`laminas/laminas-httphandlerrunner`** — PSR-7 request/emitter
- **`illuminate/database`** + **`illuminate/events`** — Eloquent, used standalone via `Capsule`
- **`guild/access`** (sibling first-party package, pulled from `github.com/nathanskky/guild-access`) — OIDC
  authentication primitives (`OidcConfiguration`, `OidcAuthenticationService`, `OidcAuthenticationMiddleware`)

### Bootstrap flow

Everything starts from `Application::configure($basePath)`, which returns an `ApplicationBuilder`. The builder is a
readonly, fluent object — each `add*` method wires one concern into the underlying `Application` container and
returns `$this`; the chain ends with `->create()` to get the `Application` back:

```php
Application::configure($basePath)
    ->enableAutoWiring()
    ->addIlluminateDatabase()
    ->addAuthentication()
    ->addRouting()
    ->create()
    ->run();
```

- `ApplicationBuilder::addRouting()` requires `{basePath}/routes/routes.php`, which must return a `callable`
  receiving the `Router` instance (this is where a consuming app registers its routes).
- `ApplicationBuilder::addAuthentication()` requires `{basePath}/config/authentication.php`, which must return an
  `OidcConfiguration` instance. This is handed to `AuthenticationServiceProvider`, a `league/container`
  `AbstractServiceProvider` that lazily registers `OidcConfiguration`, `OidcAuthenticationService`, and
  `OidcAuthenticationMiddleware` into the container.
- `ApplicationBuilder::addIlluminateDatabase()` requires `{basePath}/config/database.php`, which must return an
  array (an Eloquent connection config array). It boots a global `Illuminate\Database\Capsule\Manager` and shares
  it in the container.
- `ApplicationBuilder::enableAutoWiring()` adds a `League\Container\ReflectionContainer` as a delegate, so
  constructor-injectable classes not explicitly bound can still be resolved.
- Any failure reading/validating one of these config files throws `Guild\Framework\Exception\ConfigurationException`
  (a `LogicException`) rather than letting the underlying parse/type error propagate raw.

### Request lifecycle

`Application::run()` pulls `Router`, `SapiEmitter`, and `ServerRequestInterface` out of the container (all bound in
`Application::registerBaseBindings()`), dispatches the request through the router, and emits the response. `Router`
itself has no added behavior yet — it exists as a named extension point over `League\Route\Router` so the framework
can later customize routing without changing the public API.

### Container path bindings

`Application::setBasePath()` binds `path.base` and `path.config` into the container as `StringArgument`s;
`Application::getPath($resource)` reads these back (e.g. `getPath('config')`). `ApplicationBuilder` uses this
rather than resolving paths itself, so any new config-driven `add*` method should follow the same
`getPath('config') . '/whatever.php'` pattern.

## Conventions

- `declare(strict_types=1)` on every file.
- Framework-owned classes favor `final` (e.g. `Application`) or `readonly` (e.g. `ApplicationBuilder`) where the
  class isn't meant to be extended/mutated by consumers.
- Consuming applications are expected to supply `config/authentication.php`, `config/database.php`, and
  `routes/routes.php` under their own `$basePath` — these are load-bearing conventions, not optional files.
