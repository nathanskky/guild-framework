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

These four repos are developed side by side but are **four independent git repos**. There is no root
`composer.json` and no root git repository, so each is cloned and installed on its own. Do not invent
root-level tooling or a shared root autoloader.

| Package | Namespace | Role |
|---|---|---|
| `guild/framework` *(this one)* | `Guild\Framework\` | Application kernel / DI container |
| `guild/access` | `Guild\Access\` | IU Login (OIDC) authentication library. **This package depends on it** (`^1.0`, via VCS repo) |
| `guild/starter` | `Guild\Starter\` | Runnable example app; the primary consumer of this package |
| `iu/notifications` | `IU\Notifications\` | IU Notifications API client. Fully independent — different GitHub host, and its `<8.5` PHP constraint is mutually exclusive with this package's `~8.5.0` |

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

**`composer test` fails right now, by design.** `tests/` is empty, and `phpunit.xml` sets
`failOnEmptyTestSuite="true"` so an empty run reports `No tests executed!` and exits non-zero rather than
falsely exiting 0. **The fix is to write a test, not to remove the flag.** Treat it as a known-red baseline
until the suite exists.

**`composer analyse` is also red: 15 pre-existing errors on a clean checkout of `develop`.** All of them
are in committed code, so you will see them on a fresh clone:

- **7 × `missingType.generics`** across `Model/{Group,Role,RolePermission}.php` — level 10 wants generic
  type parameters on the Eloquent relation return types (`BelongsToMany`, `HasMany`, `BelongsTo`) and on
  the `Builder` parameter of each `#[Scope]` method.
- **7 in `ServiceProvider/ViewServiceProvider.php`** — two `method.notFound` on `$container->getPath()`,
  which is not declared on `DefinitionContainerInterface` (see [Landmines](#landmines)), plus five
  cascading errors where the resulting `mixed` flows into string concatenation and the Twig/Latte loader
  constructors.
- **1 × `missingType.iterableValue`** on `View::render()`'s `$data` parameter.

If your count is higher than 15, the extra errors are from uncommitted work in your tree — which is why
taking your own baseline before you start is still worth the ten seconds.

**Writing the first test is stricter than you expect.** `phpunit.xml` is the strictest config in the
workspace: `requireCoverageMetadata`, `beStrictAboutCoverageMetadata`, `beStrictAboutOutputDuringTests`,
`failOnPhpunitDeprecation`, `failOnRisky`, `failOnWarning`. A new test **without**
`#[CoversClass]`/`#[CoversMethod]` fails the whole run, and there are no existing tests here to copy from.
Test classes belong under `Guild\Framework\Test\` (autoload-dev maps this to `tests/`). For a worked
example of the house test style, read `access/tests/` in the sibling repo.

## Architecture

The framework wires several third-party libraries into one application container:

- **`league/container`** — the DI container (`Application extends League\Container\Container`)
- **`league/route`** — HTTP routing (`Router extends League\Route\Router`)
- **`laminas/laminas-diactoros`** + **`laminas/laminas-httphandlerrunner`** — PSR-7 request / emitter
- **`illuminate/database`** + **`illuminate/events`** — Eloquent, used standalone via `Capsule`
- **`twig/twig`** + **`latte/latte`** — optional template engines behind `View`/`TemplateEngine`
- **`robmorgan/phinx`** — migrations
- **`guild/access`** — OIDC authentication primitives

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
- `addTemplateEngine(TemplateEngine $engine)` registers `ViewServiceProvider`, binding `View::class` plus
  whichever backing engine matches the enum case (`Twig` → `Twig\Environment`/`FilesystemLoader`; `Latte` →
  `Latte\Engine`/`FileLoader`). Unlike the two methods above, this **takes the enum directly rather than
  reading a config file — there is no `config/view.php`.** Both engines load templates from
  `{basePath}/templates` by convention. `View::render()` dispatches to `Environment::render()` or
  `Engine::renderToString()` depending on which engine is bound.
- **`addAuthentication()` and `addIlluminateDatabase()` wrap config failures** in
  `Guild\Framework\Exception\ConfigurationException` (a `LogicException`) rather than letting the underlying
  parse/type error propagate raw. This is the framework's only exception type.
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

**A formatter has landed: Laravel Pint, PSR-12 preset, configured in `pint.json`.** Run `composer format` to
apply it, `composer format:check` to verify without writing. Its rules are authoritative for anything it
enforces — don't hand-fix a style issue Pint would catch, and don't fight its output. The patterns below are
*observed*, not a style guide, and now cover only what Pint doesn't decide.

- **`<?php declare(strict_types=1);` is split onto its own line** (PSR-12 §3) — Pint enforces this. The
  workspace previously used a one-line `<?php declare(strict_types=1);`, which conflicted with PSR-12; Pint's
  initial run corrected it everywhere. Don't collapse it back to one line.
- **Empty class/method bodies are two-line** (`{` then `}` on its own line) — Pint's PSR-12 preset expands
  what used to be a hugged `{}` on the same line. Don't hand-collapse it back.
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
- **The RBAC models and migrations are plumbing with nothing on top.** `src/Model/{Group,Role,RolePermission}.php`
  and the four migrations are real and functional, but no service consumes them and no Grouper/ACM logic
  exists anywhere in this package. Don't assume authorization works.

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

This is the intended model across all four Guild packages. This package has not reached v1 yet, so `main`
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
