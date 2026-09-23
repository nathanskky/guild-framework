# AGENTS.md

Guidance for AI coding agents (and new humans) working in this repository.

## What this is

Guild Framework (`guild/framework`) is a small IU-centric PHP framework — a thin composition layer over
existing PSR-friendly packages rather than a from-scratch framework. Namespace `Guild\Framework\`,
autoloaded from `src/`. It is consumed as a Composer dependency by downstream applications and is **not run
standalone**.

- **PHP:** `~8.5.0`
- **Remote:** `git@github.com:nathanskky/guild-framework.git` (SSH), or
  `https://github.com/nathanskky/guild-framework.git` if you don't have SSH keys set up
- **Default branch:** `develop`. **Work targets `develop`** — see
  [Branching and pull requests](#branching-and-pull-requests).
- **`composer.lock` is gitignored** here, so there is no lock to keep in sync.

## Sibling packages

These repos are developed side by side but are **independent git repos**. There is no root
`composer.json` and no root git repository, so each is cloned and installed on its own. Do not invent
root-level tooling or a shared root autoloader.

| Package | Namespace | Role |
|---|---|---|
| `guild/framework` *(this one)* | `Guild\Framework\` | Application kernel / DI container |
| `guild/access` | `Guild\Access\` | IU Login (OIDC) authentication library. **This package depends on it** (`^1.0`, via VCS repo) |
| `guild/grouper` | `Guild\Grouper\` | Read-only IU Grouper group-membership lookup. **This package depends on it** (`^0.1.1`, via VCS repo). Pre-1.0 — treat minor releases as potentially breaking |
| `guild/starter` | `Guild\Starter\` | Runnable example app; the primary consumer of this package |
| `iu/notifications` | `IU\Notifications\` | IU Notifications API client. Fully independent — different GitHub host, and its `<8.5` PHP constraint is mutually exclusive with this package's `~8.5.0` |
| `guild/rivet` | `Guild\Rivet\` | IU Rivet Design System components. Will be exposed via `ApplicationBuilder::addRivet()` |

`access/README.md` is the authoritative reference for the OIDC library's config fields, redirect-safety
rules, session handling, and error types. Read it before touching anything auth-related here.

## Verify your change

**Run `composer check` before you change anything and keep that output as your baseline.** Some checks are
red in this repo today, and the working tree may carry in-progress work that is not yours. Your obligation
is **no new failures** against that baseline — not a green run.

```bash
composer install       # install dependencies
composer test          # phpunit
composer analyse       # phpstan, level 10, scoped to src/
composer format:check  # pint, PSR-12 style check (writes nothing)
composer check         # test, then analyse, then style check; stops at the first failure
```

**`composer test` is green.** `phpunit.xml` sets `failOnEmptyTestSuite="true"`, so an empty run would
report `No tests executed!` and exit non-zero rather than falsely exiting 0 — keep the flag.

**`composer analyse` is red: 7 pre-existing errors on a clean checkout of `develop`.** All of them are in
committed code, so you will see them on a fresh clone:

- **7 in `ServiceProvider/ViewServiceProvider.php`** — two `method.notFound` on `$container->getPath()`,
  which is not declared on `DefinitionContainerInterface` (see [Landmines](#landmines)), plus five
  cascading errors where the resulting `mixed` flows into string concatenation and the Twig/Latte loader
  constructors.

If your count is higher than 7, the extra errors are from uncommitted work in your tree — which is why
taking your own baseline before you start is still worth the ten seconds.

**Tests are stricter than you expect.** `phpunit.xml` is the strictest config in the workspace:
`requireCoverageMetadata`, `beStrictAboutCoverageMetadata`, `beStrictAboutOutputDuringTests`,
`failOnPhpunitDeprecation`, `failOnRisky`, `failOnWarning`. A new test **without**
`#[CoversClass]`/`#[CoversMethod]` fails the whole run; list collaborators with `#[UsesClass]`. Test classes
belong under `Guild\Framework\Test\` (autoload-dev maps this to `tests/`); `tests/Authorization/` is the
local reference, following the house style of `access/tests/`:

- No PHPUnit mocks. Grouper is a real `GrouperClient` over Guzzle's `MockHandler`
  (`tests/Authorization/Support/FakeGrouper.php`); the authorization tables are in-memory SQLite
  (`Support/GrantsDatabase.php`).
- Anything touching the PHP session runs with `#[RunTestsInSeparateProcesses]` and
  `#[PreserveGlobalState(false)]`, and uses a real `session_start()`.

## Architecture

The framework wires several third-party libraries into one application container:

- **`league/container`** — the DI container (`Application extends League\Container\Container`)
- **`league/route`** — HTTP routing (`Router extends League\Route\Router`)
- **`laminas/laminas-diactoros`** + **`laminas/laminas-httphandlerrunner`** — PSR-7 request / emitter
- **`illuminate/database`** + **`illuminate/events`** — Eloquent, used standalone via `Capsule`
- **`twig/twig`** + **`latte/latte`** — optional template engines behind `View`/`TemplateEngine`
- **`robmorgan/phinx`** — migrations
- **`guild/access`** — OIDC authentication primitives
- **`guild/grouper`** — read-only Grouper group-membership lookup, for the authorization layer
- **`monolog/monolog`** — the default `Psr\Log\LoggerInterface` binding
- **`guild/rivet`** — IU Rivet Design System components for both engines

### Bootstrap flow

Everything starts from `Application::configure($basePath)`, which returns an `ApplicationBuilder`. The
builder is a readonly, fluent object — each `add*` method wires one concern into the underlying
`Application` container and returns `$this`; the chain ends with `->create()` to get the `Application` back:

```php
Application::configure($basePath)
    ->enableAutoWiring()
    ->addIlluminateDatabase()
    ->addAuthentication()
    ->addRouting()
    ->create()
    ->run();
```

- `addRouting()` requires `{basePath}/routes/routes.php`, which must return a `callable` receiving the
  `Router` instance. **This is the consuming app's routes file, not this repo's `routes/`** — see Landmines.
- `addAuthentication()` requires `{basePath}/config/authentication.php`, which must return an
  `OidcConfiguration` instance specifically (checked with `instanceof`, not against an interface). It is
  handed to `AuthenticationServiceProvider`, which lazily registers `OidcConfiguration`,
  `OidcAuthenticationService`, and `OidcAuthenticationMiddleware`.
- `addIlluminateDatabase()` requires `{basePath}/config/database.php` returning an Eloquent connection
  array. It boots a global `Illuminate\Database\Capsule\Manager` and shares it in the container.
- `enableAutoWiring()` adds a `League\Container\ReflectionContainer` as a delegate, so
  constructor-injectable classes that aren't explicitly bound can still be resolved.
- `addRivet()` registers `RivetServiceProvider`, making the Rivet components available as
  tags in whichever engine was chosen. It reads that choice from `Application::getTemplateEngine()`,
  so **it must be called after `addTemplateEngine()`** and throws `ConfigurationException`
  if it is not. Like `addTemplateEngine()` it reads no config file.
  **`RivetServiceProvider` is deliberately eager** — it implements
  `BootableServiceProviderInterface` and does its work in `boot()`. A lazy provider only
  registers when something asks for a service it declares, and nothing ever asks for
  these: templates reach the components through the engine. Left lazy, the extension
  would never be added and every `{% rvt_* %}` tag would fail as an unknown tag.
  It optionally takes a `PageDefaults`, which the `rvt_page` layout component requires.
  Passed explicitly rather than read from a config file: an *optional* config file is not
  a pattern this repo has, and an application that wants its defaults in a file can
  `require` one at the call site.
- `addTemplateEngine(TemplateEngine $engine)` registers `ViewServiceProvider`, binding `View::class` plus
  whichever backing engine matches the enum case (`Twig` → `Twig\Environment`/`FilesystemLoader`; `Latte` →
  `Latte\Engine`/`FileLoader`). Unlike the two methods above, this **takes the enum directly rather than
  reading a config file — there is no `config/view.php`.** Both engines load templates from
  `{basePath}/templates` by convention. `View::render()` dispatches to `Environment::render()` or
  `Engine::renderToString()` depending on which engine is bound.
- `addAuthorization(IdentitySource $identitySource, string $permissions, array $policies = [], ?AdminMenu $adminMenu = new AdminMenu())` requires
  `{basePath}/config/authorization.php`, which must return an `AuthorizationConfiguration` (Grouper
  connection settings, the System Admin group's ACM label, the membership TTL and stale cap, Grouper
  timeouts). It is the first `add*()` method that takes arguments **and** reads a config file, and the split
  is deliberate: **arguments carry code-level facts** — the identity source and the application's
  `Permission` enum class, which PHPStan and an IDE can check — **the config file carries everything that
  varies by deployment**, including the Grouper secret. With `IdentitySource::Oidc` it **must be called
  after `addAuthentication()`** and throws `ConfigurationException` otherwise; a CAS application never calls
  `addAuthentication()`, because Apache authenticates before PHP runs. It registers
  `AuthorizationServiceProvider`, which lazily binds `UserResolver`, `Gate` and what they need.
  `$policies` is a flat list of policy classes, each declaring `#[HandlesResource]`; they are read and
  validated on first use, never at boot, and resolved through the container. **Templates get `can()` and
  `cannot()`** whenever a template engine is configured too — `addAuthorization()` and
  `addTemplateEngine()` may be called in either order; whichever runs second registers the eager
  `AuthorizationTemplateServiceProvider`. **With `addRivet()`, System Admin group members see an
  administration menu** in `rvt_page`'s header; `$adminMenu` sets its label and position, and `null` opts
  out. The grant lookup
  resolves the shared `Capsule`, so `addIlluminateDatabase()` must also have been called.
- `withLogger(LoggerInterface $logger)` replaces the default logger (see below).
- **Config failures are wrapped** in `Guild\Framework\Exception\ConfigurationException` (a `LogicException`)
  by `addAuthentication()`, `addIlluminateDatabase()` and `addAuthorization()`, rather than letting the
  underlying parse/type error — or, for authorization, a `GrouperConfigurationException` from constructing
  `GrouperConfiguration` — propagate raw. `ConfigurationException` covers configuration only.
  Three runtime types sit alongside it, all `RuntimeException`s:
  `Exception\AuthorizationException` is a denial from `Gate::authorize()` (render as 403);
  `Exception\AuthenticationRequiredException` extends it for a denied guest, so an OIDC app can redirect
  to login instead; `Exception\AuthorizationUnavailableException` means group membership could not be
  determined — Grouper is unreachable and no cached membership is recent enough. The last is deliberately
  not a denial type, so an application can render it as a 503 rather than a 403.
  **`addRouting()` does not do this** — it is not wrapped in a try/catch, so a missing or broken
  `routes/routes.php` surfaces as a raw `require` failure. And if the file loads but returns something that
  is not callable, the `is_callable()` guard means **no routes are registered and no error is raised** —
  every request 404s with nothing to explain why. If you are adding a config-driven `add*` method, follow
  the wrapping pattern of the first two rather than `addRouting()`.

### Request lifecycle

`Application::run()` pulls `Router`, `SapiEmitter`, and `ServerRequestInterface` out of the container (all
bound in `Application::registerBaseBindings()`), dispatches the request through the router, and emits the
response. `Router` itself has no added behavior yet — it exists as a named extension point over
`League\Route\Router` so routing can be customized later without changing the public API.

### Container path bindings

`Application::setBasePath()` binds `path.base` and `path.config` into the container as `StringArgument`s;
`Application::getPath($resource)` reads these back (e.g. `getPath('config')`). `ApplicationBuilder` uses
this rather than resolving paths itself, so **any new config-driven `add*` method should follow the same
`getPath('config') . '/whatever.php'` pattern.**

### Migrations

Eloquent models in `src/Model/` back Phinx migrations in `db/migrations/` — `groups`, `roles`,
`groups_roles`, `role_permissions`, all with a `framework_` table prefix.

Migrations **cannot be run from this repo** — see Landmines for why and for the real command.

**Writing a migration: follow the Phinx documentation** — [phinx.org/docs](https://phinx.org/docs), source
at [github.com/cakephp/phinx](https://github.com/cakephp/phinx). Match the existing files in
`db/migrations/` for structure rather than treating anything here as a house style.

Two details are specific to this project and worth knowing up front:

- **`created_by` / `updated_by` are `string` with `'limit' => 8`** — they hold an IU username, not a user ID.
- **Foreign key columns need `'signed' => false`** to match the unsigned primary keys they reference.
  Without it the column types disagree and the constraint fails.

## Conventions

**This repo is formatted with Laravel Pint (PSR-12 preset), configured in `pint.json`.** Run `composer
format` to apply it, `composer format:check` to verify without writing. Its rules are authoritative for
anything it enforces — don't hand-fix a style issue Pint would catch, and don't fight its output. The
patterns below are *observed*, not a style guide, and cover only what Pint doesn't decide.

- **Use `final` and `readonly` where they make sense for the class in front of you** — not to match what
  neighboring classes or sibling packages happen to do. Today `Application` is `final`, `ApplicationBuilder`
  and `View` are `readonly`, and `Router`, `ConfigurationException`, the service providers, and the models
  are plain classes. That is the current state, not a policy to conform to; don't "fix" a class to match its
  neighbors in either direction.
  One thing genuinely worth weighing here: this is a library, so adding `final` to an already-published
  class is a breaking change for anyone extending it.
- Directory names under `src/` are **singular**: `Controller`, `Model`, `Exception`, `ServiceProvider`.
- **PHPStan level is 10 here.** Levels differ per package (`access` at `max`, `notification` at 5, `starter`
  has none). Do not assume one bar across the workspace.
- Eloquent models are the one place typed properties are *not* used — they need untyped
  `protected $attributes`.

**Adding a service provider: follow the league/container documentation** —
[container.thephpleague.com/5.x/service-providers](https://container.thephpleague.com/5.x/service-providers/).
Match the existing files in `src/ServiceProvider/` for structure rather than treating anything here as a
house style.

One detail specific to this project: providers receive whatever they need by **constructor injection from
`ApplicationBuilder`**, rather than reading a config file themselves. The builder does any loading and
validation (see [Bootstrap flow](#bootstrap-flow)) and passes the result in. What that result is depends
entirely on the service.

**A consuming application only needs the config file for the functionality it actually enables.** Each
`add*` method reads its own file and nothing else, so an app that never calls a given method never needs
that file:

| Builder call | File it requires, under the app's `$basePath` |
|---|---|
| `addRouting()` | `routes/routes.php` |
| `addAuthentication()` | `config/authentication.php` |
| `addIlluminateDatabase()` | `config/database.php` |
| `addTemplateEngine()` | none — takes the enum directly; renders from `templates/` |
| `addRivet()` | none — but requires `addTemplateEngine()` to have been called first |
| `addAuthorization()` | `config/authorization.php` — plus arguments for the code-level facts; with OIDC, requires `addAuthentication()` first |
| `enableAutoWiring()` | none |

`guild/starter` is the worked example: it does not call `addAuthentication()`, so its
`config/authentication.php` is never loaded and the app runs without it.

## Landmines

- **`db/seeds/` and `routes/` are empty but reserved — do not delete them.** `routes/` is where
  *framework-owned* routes will eventually live (framework administration / settings interfaces, not yet
  written). `db/seeds/` is already referenced by `phinx.php`. Note that `routes/` is **not** the file
  `addRouting()` loads: that is the *consuming app's* `routes/routes.php`, resolved from its `$basePath`.
- **Migrations cannot be run from this repo.** `phinx.php` does `require dirname(__FILE__, 4) . '/bootstrap/app.php'`,
  which only resolves when this package sits at `<app>/vendor/guild/framework/phinx.php`. Run it from the
  consuming app instead:
  ```bash
  cd ../starter && vendor/bin/phinx -c vendor/guild/framework/phinx.php migrate -e framework
  ```
  `phinx.php` also boots the entire application just to extract a PDO handle from the container, so **a live
  database connection is required merely to load the config file** — not just to run a migration. DB env
  vars must be valid before any Phinx command.
- **The `framework_` table prefix is set in three places.** `phinx.php` sets `table_prefix` and
  `default_migration_table`; the migrations name bare tables (`groups`, `roles`); the Eloquent models
  hard-code the prefixed names in `#[Table]`. Change one, change all three.
- **`ViewServiceProvider` calls a method its type doesn't have.** It calls `$container->getPath(...)`, but
  `$this->getContainer()` is typed `DefinitionContainerInterface`, which has no `getPath()`. It works only
  because the concrete container is `Guild\Framework\Application`. This is a real level-10 finding, not a
  false positive.
- **`Application::getPath()` throws on unknown resources.** It calls `$this->get('path.' . $resource)` with
  no existence check, so an unrecognized resource raises a container `NotFoundException` rather than
  returning `null` as its `?string` signature suggests. Only `path.base` and `path.config` are bound.
- **Authorization has a user, a Gate, policies and template functions, but no admin UI yet.**
  `UserResolver::current()` returns a `User` or `null` for a guest. `Gate::allows()/denies()/authorize()`
  take a case of the application's `Permission` enum and an optional resource. Without a resource the
  user's roles decide; with one, the roles must grant the permission **and** the resource's policy method
  (bound by `#[Handles]`) must agree. The System Admin group bypasses everything, except when membership
  is stale. Read `AUTHORIZATION-PLANNING.md` at the workspace root before extending it.
- **Policy mistakes throw `ConfigurationException` on first use, for every user** — no policy for the
  resource's exact class, no `#[Handles]` method for the permission, duplicates, a non-`bool` return, a
  case from another enum, or a first parameter that is not `User`/`?User`. The policy is resolved before
  any allow or deny decision, so a mistake cannot hide behind a user who lacks the grant.
- **Templates name a permission by case or by stored value.** `can('documents.update', document)` and
  `can(enum('App\\AppPermission').DocumentsUpdate, document)` (Twig) are equivalent; an unknown value
  throws `ConfigurationException` at render. Templates are not statically analysed, so the value string
  loses nothing a case would have caught.
- **`rvt_page` asks for its header items at render time.** `RivetServiceProvider` builds the renderer with
  `Rivet\ContainerNavigation`, which delegates to whatever the container binds under
  `Guild\Rivet\Page\NavigationProvider` — `AdminNavigation` when authorization is added with a menu — and
  otherwise returns the configured `PageDefaults::$navItems`. The lookup happens per page, which is what
  lets `addRivet()` and `addAuthorization()` be called in either order. `AdminNavigation` never throws for
  an unanswerable System Admin check; it leaves the menu out. The menu is cosmetic: the `/framework`
  routes must gate themselves.
- **`View::render()` unwraps `AuthorizationUnavailableException`.** Twig wraps anything thrown inside a
  template function in `Twig\Error\RuntimeError`; Latte does not. `View` rethrows the unavailable exception
  as itself under both engines so an application's 503 handling sees it. Everything else stays wrapped,
  keeping Twig's template name and line. Rendering through `Environment` directly bypasses this.
- **Only Grouper membership is cached; grants are read fresh.** Membership lives in the PHP session under
  `guild_framework_membership` for the configured TTL, is served stale up to the stale cap while Grouper is
  unreachable (with a warning logged every time), and after that `AuthorizationUnavailableException` is
  thrown. `User::isSystemAdmin()` never uses stale membership. The role/permission mapping is one query per
  request, so an administrator's change takes effect on the next request.
- **The framework starts a PHP session on the CAS path.** Nothing else does there — Apache authenticates
  before PHP runs — so `Authorization\Membership\Session` starts one, with the same cookie parameters as
  `guild/access`, the first time membership is needed. Public pages that never check authorization get no
  session cookie.
- **The default logger writes through PHP's `error_log()`.** `Application` binds `LoggerInterface` to a
  Monolog `Logger` on the `framework` channel with an `ErrorLogHandler`, so records land wherever the
  runtime sends PHP errors. In the `php:*-apache` image that is Apache's `ErrorLog`, which is linked to
  `/dev/stderr`, which is what a Kubernetes log collector reads. **That is unverified for AppKube's
  IU-provided image**; if framework warnings do not reach the log platform there, call `withLogger()` with a
  logger aimed at stderr. Apache prefixes every such line `[php:notice]` regardless of level; the
  `framework.WARNING:` text is the real level.
## Branching and pull requests

**Do not commit directly to `develop`.** Work on a feature branch and open a pull request against
`develop`.

```bash
git checkout develop && git pull        # start from an up-to-date develop
git checkout -b <short-descriptive-name>
# ... commit your work ...
git push -u origin <short-descriptive-name>
```

Then open a PR **targeting `develop`**, not `main`. Nothing routine should land on `main` directly.

`composer test` is a known-red baseline here (empty `tests/`) — see
[Verify your change](#verify-your-change). Don't let a PR add *new* failures beyond it.

### Branch and release model

Three stages, and **tags live on `main`, never on `develop`**:

```
feature branch  --PR-->  develop  --PR-->  main  --> tag (release)
```

- **`develop`** accumulates day-to-day work. This is where your feature PR goes.
- **`main`** is the released state. `develop` is merged into it **via its own pull request** when the
  accumulated work is ready to release.
- **Tags are applied to `main`** after that merge.

This is the intended model across all the Guild packages. This package has not reached v1 yet, so `main`
and tagging are not in use here today — but assume this flow for new work rather than inventing another.

**One consequence specific to this package:** `guild/starter` requires `dev-develop`, which tracks the tip
of `develop` directly rather than a tag. So **anything merged to `develop` reaches that consumer on its next
`composer update`**, with no release step in between — a broken merge is felt immediately downstream. That
is the opposite of `guild/access`, where consumers use a `^1.0` tag constraint and see nothing until a tag
is cut.

## Getting a change to consumers

**This is the most common way to waste a session here.** Consumers pull this package as a *downloaded
zipball* from GitHub — there is no path repository and no symlink. `starter/vendor/guild/framework/` is a
real directory containing a snapshot, typically several commits behind this repo's HEAD. **Editing
`src/` here changes nothing in a consuming app, silently.**

Two ways to work:

**Local dev loop** — temporarily point the consumer at your working tree. In `starter/composer.json`, add
this *above* the existing VCS entries, then `composer update guild/framework`:

```json
{ "type": "path", "url": "../framework", "options": { "symlink": true } }
```

**Revert that before committing.** It is a local-only convenience.

**Real publish loop:**

1. Land your change on `develop` via a pull request (see
   [Branching and pull requests](#branching-and-pull-requests)).
2. Confirm it is **merged and pushed** — this is the step people skip. Consumers declare a VCS repository
   at `https://github.com/nathanskky/guild-framework.git`, so Composer resolves from GitHub and cannot see
   local commits or an unmerged feature branch.
3. In the consumer, `composer update guild/framework`. `starter` requires `dev-develop`, which tracks the
   **tip of `develop`**, so a merged PR is picked up with no `composer.json` edit — and conversely, a
   branch that is pushed but not yet merged is invisible to it.

**Never hand-edit `starter/vendor/guild/framework/`** — the next `composer install` reverts it and your
change never reaches the real package.

Note the mixed auth paths: this repo's `origin` is SSH, but it pulls `guild/access` over **HTTPS**, so
`composer update` may prompt for GitHub credentials in places where `git push` works fine. Bumping
`guild/access` additionally requires a **new git tag** in that repo, because the constraint here is `^1.0`
(a tag constraint), not a branch.
