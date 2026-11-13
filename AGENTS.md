# AGENTS.md: guild/framework

> For maintainers of guild/framework and their agents. App developers start at [docs/index.md](docs/index.md).

The [workspace handbook](../AGENTS.md) covers cross-repo workflow and conventions.

guild/framework is the application kernel for Guild apps: a thin composition layer over league/container,
league/route, Eloquent, Twig, Latte, guild/access, guild/grouper and guild/rivet. It isn't run on its
own; apps install it.

## Verify

`composer check` must show no new failures. `composer analyse` reports 7 known errors in
`ServiceProvider/ViewServiceProvider.php`; the comment in its `register()` explains them. Take your own
baseline first, because uncommitted work in your tree adds to that count.

## Keeping docs current

Every code change gets a docs check in the same commit. Check README, `docs/`, AGENTS.md, the docblocks
of public classes and the comments at the changed code for anything the change makes wrong or incomplete:

- Update a page that describes the changed behavior.
- If no page covers new public behavior, add one to `docs/` and list it in `docs/index.md`.
- If a maintainer needs a fact the code doesn't show, add a comment where the code is.
- If the behavior is gone, remove its page and its index entry.
- If the change adds or renames a domain term, update `docs/reference/glossary.md`.

Then check the pages that link to a changed page for text that's now wrong, and link a new page from
related pages. A non-obvious choice gets a decision record in `docs/decisions/`.

Public behavior here includes each `add*()` call's file, arguments and order rules, the status pages and
their text, the template functions, the slot rules, and the administration pages. A release that changes
what an app must do gets a section in `docs/upgrading.md`. `ApplicationBuilder`, `Gate`, `User`, the
configuration classes and the extension interfaces keep their API reference in docblocks, so a change to
them updates the docblock.

## Conventions

- Mark every class an app must not construct or type against `@internal`.
- Directory names under `src/` are singular, such as `Controller` and `ServiceProvider`.
- Each `add*()` concern has one service provider, which gets its settings by constructor injection from `ApplicationBuilder`.
- A config-driven `add*()` reads `getPath('config') . '/name.php'` and wraps failures in `ConfigurationException`.
- `add*()` arguments carry code-level facts; config files carry what varies by deployment.
- A provider whose services nothing asks for, such as one that extends a template engine, must be eager.
- Eloquent models keep untyped `protected $attributes`; everywhere else uses typed properties.

`addRouting()` doesn't follow the wrapping rule; its docblock says what that costs, so don't copy it. For
providers and migrations, follow the league/container and Phinx documentation and match the existing
files.

In migrations, `created_by` and `updated_by` are `string` with `'limit' => 64`, because they hold
usernames and persona usernames exceed IU's 8 characters. Foreign key columns need `'signed' => false` to
match the unsigned primary keys.

## Tests

- Give every test class `#[CoversClass]` or `#[CoversMethod]`, and list collaborators with `#[UsesClass]`; PHPUnit fails the run otherwise.
- Don't use PHPUnit mocks. Use guild/grouper's `FakeGrouper` and in-memory SQLite through `tests/Support/GrantsDatabase.php`.
- Pass a `Testing\ArraySessionStore`, or override the container's `SessionStore` binding with one.
- Run in separate processes only tests of `NativeSessionStore` and of guild/access's session reads.

`tests/Authorization/` is the local model for new tests.

## Gotchas

- `routes/authorization.php` holds the framework's own routes, not the app's; `addRouting()` loads the app's file.
- Migrations run only from a consuming app, with a live database; the comment in `phinx.php` gives the command.
- The `framework_` table prefix is set in three places; change all three together.
- `Application::getPath()` throws for any resource but `base` and `config`.
- `View::render()` rethrows `AuthorizationUnavailableException` unwrapped, so the 503 survives Twig's wrapping.
- League's not-found path leaves class-name middleware unresolved; `StatusPageResponder` resolves it.
- Eloquent query-builder calls lose model types under PHPStan; load models, then sort the collection.
- `Gate` resolves the policy before deciding, so a policy mistake throws for every user.
- The admin menu is cosmetic; every `/framework` route gates itself with `RequireSystemAdmin`.
- On the CAS path, `NativeSessionStore` starts the PHP session, with the same cookie flags as guild/access.

The comment at each gotcha's code explains the reason.

The default logger writes through `error_log()`, which reaches standard error in the `php:*-apache`
image. Whether it reaches the log platform on IU's AppKube image is unverified. If framework warnings go
missing there, apps can call `withLogger()` with a logger aimed at standard error.
