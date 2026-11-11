# guild/framework

A small IU-centric PHP framework for web applications.

It is a thin composition layer over existing packages rather than a from-scratch framework. An
application builds one container with a fluent `ApplicationBuilder`, opting in to each concern it needs:

- **Routing** — [`league/route`](https://route.thephpleague.com/6.x/) over a
  [`league/container`](https://container.thephpleague.com/5.x/) DI container, with PSR-7 requests from
  `laminas/laminas-diactoros`
- **Database** — Eloquent (`illuminate/database`) used standalone
- **Authentication** — IU Login over OIDC, via [`guild/access`](https://github.com/nathanskky/guild-access)
- **Templates** — Twig or Latte, behind one `View` service
- **Rivet** — IU Rivet Design System components in either engine, via
  [`guild/rivet`](https://github.com/nathanskky/guild-rivet)
- **Authorization** — Grouper group membership (via
  [`guild/grouper`](https://github.com/nathanskky/guild-grouper)) mapped to roles and permissions, with
  resource policies and administration pages

[`guild/starter`](https://github.com/nathanskky/guild-starter) is a runnable application built on it.

## Requirements

- PHP `~8.5.0`
- For `addIlluminateDatabase()` and `addAuthorization()`: a database Eloquent supports
- For `addAuthentication()`: a registered IU Login OIDC client
- For `addAuthorization()`: a Grouper service account, and either `addAuthentication()` or Apache
  `mod_auth_cas` in front of the application

## Installation

Composer reads `repositories` only from your
application's own `composer.json`, so declare this package's VCS-hosted dependencies too:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/nathanskky/guild-framework.git" },
        { "type": "vcs", "url": "https://github.com/nathanskky/guild-access.git" },
        { "type": "vcs", "url": "https://github.com/nathanskky/guild-grouper.git" },
        { "type": "vcs", "url": "https://github.com/nathanskky/guild-rivet.git" }
    ],
    "require": {
        "guild/framework": "^2.0"
    }
}
```

```bash
composer update guild/framework
```

## Usage

Build the application once, from the application's root directory:

```php
use App\AppPermission;
use Guild\Framework\Application;
use Guild\Framework\Authorization\IdentitySource;
use Guild\Framework\TemplateEngine;

Application::configure($basePath)
    ->enableAutoWiring()
    ->addIlluminateDatabase()
    ->addAuthentication()                         // OIDC only; a CAS application omits it
    ->addTemplateEngine(TemplateEngine::Twig)
    ->addRivet(require $basePath . '/config/rivet.php')
    ->addAuthorization(IdentitySource::Oidc, AppPermission::class)
    ->addRouting()
    ->create()
    ->run();
```

Every `add*()` method is optional, and each reads only its own file under `$basePath`, so an application
needs only the files for what it enables:

| Builder call | Reads | Notes |
|---|---|---|
| `addRouting()` | `routes/routes.php` | |
| `addIlluminateDatabase()` | `config/database.php` | |
| `addAuthentication()` | `config/authentication.php` | Not read when an installed extension chose the sign-in method |
| `addTemplateEngine(TemplateEngine)` | nothing | Renders from `templates/` |
| `addRivet(?PageDefaults)` | nothing | Call after `addTemplateEngine()` |
| `addAuthorization(...)` | `config/authorization.php`, `config/grouper.php` | `config/grouper.php` is not read when an installed extension chose the group directory. Needs `addIlluminateDatabase()`; with OIDC, call after `addAuthentication()` |
| `enableAutoWiring()` | nothing | |
| `withLogger(LoggerInterface)` | nothing | |

A missing or invalid config file throws `Guild\Framework\Exception\ConfigurationException`, except under
`addRouting()` (see below).

### `addRouting()`

`routes/routes.php` returns a callable that receives the `Guild\Framework\Router` (a `league/route`
router). Controllers are resolved from the container:

```php
use App\HomeController;
use Guild\Framework\Router;

return static function (Router $router): void {
    $router->map('GET', '/', [HomeController::class, 'index']);
};
```

A request no route answers gets a short status page: 404 for an address with no page, and 405, with an
`Allow` header, for a page asked with a method it doesn't take. The page uses the application's Rivet
layout after `addRivet()`, and is a plain HTML document otherwise. Router-level middleware wraps it as it
wraps any page.

If the file returns something that is not callable, no routes are registered and no error is raised.
`/framework` is reserved for the framework's own pages.

### `addIlluminateDatabase()`

`config/database.php` returns an Eloquent connection array. It boots a global
`Illuminate\Database\Capsule\Manager`, shared in the container.

```php
return [
    'driver' => 'mysql',
    'host' => $_ENV['MYSQL_HOST'],
    'port' => $_ENV['MYSQL_PORT'],
    'database' => $_ENV['MYSQL_DATABASE'],
    'username' => $_ENV['MYSQL_USERNAME'],
    'password' => $_ENV['MYSQL_PASSWORD'],
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
];
```

### `addAuthentication()`

`config/authentication.php` returns a `Guild\Access\Authentication\OIDC\OidcConfiguration`. The
container then provides `OidcAuthenticationService`, `OidcAuthenticationMiddleware`,
`Guild\Framework\Authentication\SignInMethod` and `Guild\Framework\Middleware\RequireSignIn`; add
`RequireSignIn` to the routes that need a signed-in user:

```php
use Guild\Framework\Middleware\RequireSignIn;

$router->lazyMiddleware(RequireSignIn::class);
```

`OidcAuthenticationMiddleware` still works, but always uses IU Login. Neither it nor
`OidcAuthenticationService` is bound when an installed extension chose the sign-in method, so routes
should use `RequireSignIn`.

See the [`guild/access` README](https://github.com/nathanskky/guild-access) for the configuration fields,
redirect rules and session handling.

### Signing out

`addAuthentication()` mounts `POST /framework/sign-out`, which ends the visitor's session. It accepts only
a form that carries the session's CSRF token, so a link or a `GET` cannot sign anyone out. Templates get
`sign_out_form()`, which renders the whole form with a **Sign out** button; call `addAuthentication()` and
`addTemplateEngine()` in either order.

```twig
{{ sign_out_form() }}
```

```latte
{sign_out_form()}
```

With IU Login, the visitor lands on IU Login's logout page, or on `defaultReturnUrl` when there was no
IU Login session. A sign-in method chosen by an extension decides for itself.

Signing out also replaces the session's CSRF token, so a form issued while signed in is refused afterwards.
An extension's own forms use the same token: render `Guild\Framework\Admin\Csrf::field()` in the form, put
`Guild\Framework\Middleware\VerifyCsrfToken` on the route, and call `Csrf::rotate()` when the signed-in user
changes.

### `addTemplateEngine()`

Takes `TemplateEngine::Twig` or `TemplateEngine::Latte` and binds `Guild\Framework\View`, which loads
templates from `{basePath}/templates`:

```php
use Guild\Framework\View;
use Laminas\Diactoros\Response\HtmlResponse;

final readonly class HomeController
{
    public function __construct(private View $view)
    {
    }

    public function index(): HtmlResponse
    {
        return new HtmlResponse($this->view->render('home.html.twig', ['name' => 'World']));
    }
}
```

### `addRivet()`

Registers the Rivet components as tags in the configured engine (`{% rvt_page %}` in Twig, `{rvtPage}`
in Latte). It must come after `addTemplateEngine()`. The optional `Guild\Rivet\Page\PageDefaults` carries the
application-wide values the `rvt_page` layout reads — app title, navigation, footer links:

```php
use Guild\Rivet\Page\PageDefaults;

return new PageDefaults(
    appTitle: 'My Application',
    navItems: [
        ['label' => 'Home', 'href' => '/'],
    ],
);
```

See the [`guild/rivet` README](https://github.com/nathanskky/guild-rivet) for the components.

### `withLogger()`

The container binds `Psr\Log\LoggerInterface` to a Monolog logger on the `framework` channel that writes
through PHP's `error_log()`. Pass your own logger to replace it.

## Authorization

A user's IU Grouper groups are mapped to roles, and roles are granted permissions. Both mappings live in
the application's database and are managed by System Admins in the framework's administration pages.

### Setting it up

1. **Declare the permissions** the application checks, as one string-backed enum implementing
   `Guild\Framework\Authorization\Permission`. Each value is the name stored for a grant, so renaming one
   orphans the grants that use it.

   ```php
   use Guild\Framework\Authorization\Permission;

   enum AppPermission: string implements Permission
   {
       case DocumentsView = 'documents.view';
       case DocumentsUpdate = 'documents.update';
   }
   ```

2. **Configure the deployment** in two files. `config/authorization.php` returns an
   `AuthorizationConfiguration`:

   ```php
   use Guild\Framework\Authorization\AuthorizationConfiguration;

   return new AuthorizationConfiguration(
       // The ACM label of the group whose members administer the application.
       systemAdminGroup: $_ENV['AUTHORIZATION_SYSTEM_ADMIN_GROUP'],
   );
   ```

   | Field | Default | Description |
   |---|---|---|
   | `systemAdminGroup` | `null` (blank) | ACM label of the System Admin group. Members are allowed everything. Required unless an installed extension supplies the label; with neither, boot fails. |
   | `membershipTtl` | `900` | Seconds a user's Grouper membership is cached in the session. |
   | `staleCap` | `3600` | Seconds past the TTL cached membership may still be used while Grouper is unreachable. `0` disables. |

   `config/grouper.php` returns a `GrouperDirectoryConfiguration`, saying how to reach Grouper:

   ```php
   use Guild\Framework\Authorization\Membership\GrouperDirectoryConfiguration;
   use Guild\Grouper\GrouperConfiguration;

   return new GrouperDirectoryConfiguration(
       grouper: new GrouperConfiguration(
           serviceUrl: $_ENV['GROUPER_SERVICE_URL'],
           username:   $_ENV['GROUPER_USERNAME'],
           password:   $_ENV['GROUPER_PASSWORD'],
       ),
   );
   ```

   | Field | Default | Description |
   |---|---|---|
   | `grouper` | *(required)* | A `GrouperConfiguration`; see the [`guild/grouper` README](https://github.com/nathanskky/guild-grouper). |
   | `timeout` | `10.0` | Seconds to wait for a Grouper response. |
   | `connectTimeout` | `3.0` | Seconds to wait for a connection to Grouper. |

3. **Call `addAuthorization()`**:

   ```php
   ->addAuthorization(
       IdentitySource::Oidc,           // or IdentitySource::Cas, when Apache mod_auth_cas sets REMOTE_USER
       AppPermission::class,
       policies: [DocumentPolicy::class],   // optional
   )
   ```

   With `IdentitySource::Oidc`, call `addAuthentication()` first. The optional `adminMenu` argument (an
   `Authorization\AdminMenu` with a `label` and zero-based `position`) places the administration menu;
   see [Administration pages](#administration-pages).

4. **Run the framework's migrations** from the application, which creates the `framework_groups`,
   `framework_roles`, `framework_groups_roles` and `framework_role_permissions` tables. The Phinx config
   loads the application from `bootstrap/app.php`, so the database must be reachable:

   ```bash
   vendor/bin/phinx -c vendor/guild/framework/phinx.php migrate -e framework
   ```

   Run the same command after upgrading the framework, since a release can add migrations. The
   `created_by` and `updated_by` columns hold usernames of up to 64 characters.

   Tests can create the same tables on any connection, such as in-memory SQLite, with
   `Guild\Framework\Testing\FrameworkSchema::migrate($connection)`.

### Checking permissions

Constructor-inject `Guild\Framework\Authorization\Gate`:

```php
$gate->allows(AppPermission::DocumentsUpdate);             // bool
$gate->denies(AppPermission::DocumentsUpdate, $document);  // bool
$gate->authorize(AppPermission::DocumentsUpdate, $document); // throws when denied
```

Without a resource, the user's roles decide. With one, the roles must grant the permission **and** the
resource's policy must agree. A guest holds no grants. System Admin group members are allowed everything.

`Guild\Framework\Authorization\UserResolver::current()` returns the current `User`, or `null` for a guest.
`User` exposes `username()`, `groups()`, `inGroup()`, `roles()`, `permissions()`, `hasPermission()` and
`isSystemAdmin()`, plus `attribute()` for OIDC claims.

### Policies

A policy decides *which* resources a user holding the permission may act on. It names its resource class
with `#[HandlesResource]` and binds one method per permission with `#[Handles]`. Each method takes the
user — `?User` to also be asked about guests — and the resource, and returns `bool`:

```php
use Guild\Framework\Authorization\Handles;
use Guild\Framework\Authorization\HandlesResource;
use Guild\Framework\Authorization\User;

#[HandlesResource(Document::class)]
final class DocumentPolicy
{
    #[Handles(AppPermission::DocumentsUpdate)]
    public function update(User $user, Document $document): bool
    {
        return $document->owner === $user->username();
    }

    #[Handles(AppPermission::DocumentsView)]
    public function view(?User $user, Document $document): bool
    {
        return $document->public || $document->owner === $user?->username();
    }
}
```

Policies are resolved through the container, so constructor dependencies need autowiring or explicit
bindings. A resource matches its policy by exact class. A declaration mistake — no policy for the
resource, no method for the permission, a wrong signature — throws `ConfigurationException` the first time
the policy is used, for every user.

### Templates

With both a template engine and authorization configured, in either order, templates get `can()`,
`cannot()` and `current_user()`. A permission can be named by its stored value:

```twig
{% if can('documents.update', document) %}…{% endif %}
```

```latte
{if can('documents.update', $document)}…{/if}
```

An unknown value throws `ConfigurationException` when the template renders.

`current_user()` returns the signed-in `User`, or `null` for a guest:

```twig
{% if current_user() %}Signed in as {{ current_user().username }}{% endif %}
```

```latte
{if current_user()}Signed in as {current_user()->username()}{/if}
```

### Failures

| Exception | Meaning | Response |
|---|---|---|
| `Exception\AuthorizationException` | `Gate::authorize()` denied an authenticated user | 403 "Not authorized" |
| `Exception\AuthenticationRequiredException` | `Gate::authorize()` denied a guest; extends `AuthorizationException` | The sign-in method's challenge under OIDC; 403 "Sign in required" under CAS; 403 "Not authorized" if someone is signed in after all |
| `Exception\AuthorizationUnavailableException` | Grouper is unreachable and no cached membership is recent enough | 503 "Temporarily unavailable" |

`addAuthorization()` produces these responses for every route, the application's own included. A guest
under OIDC gets the sign-in method's challenge; a signed-in visitor is never challenged, because signing in
again cannot help them, so an application that throws `AuthenticationRequiredException` itself still gets a
403 for someone already signed in. The pages use the application's `rvt_page` layout with
`addRivet()`, and a plain HTML5 document without it.

`AuthorizationFailureResponder` is router-level middleware that `addAuthorization()` adds. Router-level
middleware runs first-added-outermost, so an application's own router-level exception middleware in
`routes.php` sees authorization exceptions only when `addRouting()` comes after `addAuthorization()`.
Route- and group-level middleware always sees them first.

`View::render()` rethrows `AuthorizationUnavailableException` raised inside a template unwrapped, under
both engines. The System Admin check never uses stale membership.

### Administration pages

`addAuthorization()` mounts pages at `/framework/authorization`, gated on the System Admin group, where
administrators register Grouper groups by ACM label, map them to roles, and create, edit and delete roles
and their grants. Under OIDC a guest is asked to sign in; under CAS, protect `/framework` in the Apache
configuration. With `addRivet()` the pages use the application's `rvt_page` layout, and System Admins see
a **System settings** menu in its header; pass `adminMenu: null` to leave the header alone.

## Extensions

A `guild-extension` package changes how an application signs people in, where it reads group membership, or
which group administers it, without the application writing any code for it. Installing the package is
enough: `Application::configure()` finds it.

A package declares itself in its `composer.json`:

```json
{
    "name": "acme/guild-sso",
    "type": "guild-extension",
    "extra": {
        "guild": {
            "extension": "Acme\\Sso\\SsoExtension"
        }
    }
}
```

`SsoExtension` implements `Guild\Framework\Extension\ApplicationExtension`. Its `configure()` runs before
the application's own `add*()` calls and chooses what it supplies through the builder; its `boot()` runs
at the end of `create()`, once the container is ready:

| Builder method | Chooses | Replaces |
|---|---|---|
| `useSignInMethod(class-string<SignInMethod>)` | How people sign in and out | `config/authentication.php` |
| `useGroupDirectory(class-string<GroupDirectory>)` | Where a user's groups come from | `config/grouper.php` |
| `useSystemAdminGroup(string)` | The System Admin group's ACM label | `systemAdminGroup` in `config/authorization.php` |

An extension that chose the sign-in method still needs `addAuthentication()`, followed by
`addAuthorization(IdentitySource::Oidc, ...)`; `IdentitySource::Cas` is refused. Each slot can be chosen
once, so two extensions choosing the same one fail at boot with a message naming both. Every choice that takes
effect is logged at `notice`.

Choose in `configure()`: a choice made after the `add*()` call that reads the slot is refused. A choice that
nothing reads, such as a group directory in an application that never calls `addAuthorization()`, is logged at
`notice` and otherwise ignored. A chosen class must be buildable by the container: bind it in the extension's
`boot()`, or rely on `enableAutoWiring()`; `create()` fails otherwise. `$builder->path('config')` gives the
application's config directory, for an extension that reads a file of its own there.

With a chosen group directory there is no Grouper connection, so the three actions that register a group
by its Grouper name answer 503; editing, mapping and unregistering groups still work.

`Application::configure($basePath, $extensions)` takes an explicit list instead, which is how tests boot
an application with the extensions they want. A `composer install --no-dev` build never discovers an
extension that was installed with `require-dev`, and neither does `composer dump-autoload --no-dev` run over a
development install.

## Upgrading to 2.1.4

Nothing needs action. A form refused by `VerifyCsrfToken`, sign-out included, gets the "Form expired" status
page: the application's layout with `addRivet()`, plain HTML without it. Without Rivet it used to carry an
"Administration" header linking to `/framework/authorization`, even in an application with no administration
pages.

## Upgrading to 2.1.3

Nothing needs action. A signed-in visitor whose request throws `AuthenticationRequiredException` gets the
403 "Not authorized" page instead of the sign-in method's challenge, because signing in again cannot help
them. `Gate` throws that exception only for a guest, so this changes nothing unless application code throws
it itself. A custom `SignInMethod::challenge()` is only ever called for a guest.

## Upgrading to 2.1.2

Nothing needs action. 2.1.2 fixes 2.1.1, where a request no route answers failed with a `TypeError` in an
application with router middleware registered by class name.

## Upgrading to 2.1.1

A request no route answers is a 404 or 405 page instead of an uncaught `League\Route\Http\Exception\NotFoundException`
or `MethodNotAllowedException`. Code that caught those exceptions around `Router::dispatch()` no longer sees them.

## Upgrading to 2.1

Run the framework's migrations (see [Setting it up](#setting-it-up)): 2.1 widens the `created_by` and
`updated_by` columns to 64 characters. Nothing else needs action.

## Upgrading to 2.0

Changes that need action or can break code written against 1.x:

- Grouper settings move from `config/authorization.php` to a new `config/grouper.php`
- `OidcIdentityReader` is removed; use `SignInMethod`
- The `AuthenticationServiceProvider` and `AuthorizationServiceProvider` constructors changed (internal wiring)
- `AuthorizationConfiguration::$systemAdminGroup` is `?string`
- Under OIDC the administration pages use `RequireSignIn`
- `addAuthorization()` answers authorization failures for every route
- After upgrading, sign in and sign out once where the redirect URI is registered with IU Login

The Grouper move is the one every application with `addAuthorization()` hits: `AuthorizationConfiguration`
no longer takes a `GrouperConfiguration` or Grouper timeouts. Return them from `config/grouper.php` as a
`GrouperDirectoryConfiguration` instead (see [Setting it up](#setting-it-up)); the error at boot says the
same. Sign-in and sign-out against IU Login are not covered by automated tests, so confirm them once in an
environment whose redirect URI is registered.

## Contributing

See [`AGENTS.md`](AGENTS.md) for the architecture, verification commands, known landmines and the branch
workflow.
