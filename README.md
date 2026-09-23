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

There is no tagged release; require the `develop` branch. Composer reads `repositories` only from your
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
        "guild/framework": "dev-develop"
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
| `addAuthentication()` | `config/authentication.php` | |
| `addTemplateEngine(TemplateEngine)` | nothing | Renders from `templates/` |
| `addRivet(?PageDefaults)` | nothing | Call after `addTemplateEngine()` |
| `addAuthorization(...)` | `config/authorization.php` | Needs `addIlluminateDatabase()`; with OIDC, call after `addAuthentication()` |
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
container then provides `OidcAuthenticationService` and `OidcAuthenticationMiddleware`; add the middleware
to the routes that need a signed-in user:

```php
$router->lazyMiddleware(OidcAuthenticationMiddleware::class);
```

See the [`guild/access` README](https://github.com/nathanskky/guild-access) for the configuration fields,
redirect rules and session handling.

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

2. **Configure the deployment** in `config/authorization.php`, which returns an
   `AuthorizationConfiguration`:

   ```php
   use Guild\Framework\Authorization\AuthorizationConfiguration;
   use Guild\Grouper\GrouperConfiguration;

   return new AuthorizationConfiguration(
       grouper: new GrouperConfiguration(
           serviceUrl: $_ENV['GROUPER_SERVICE_URL'],
           username:   $_ENV['GROUPER_USERNAME'],
           password:   $_ENV['GROUPER_PASSWORD'],
       ),
       // The ACM label of the group whose members administer the application.
       systemAdminGroup: $_ENV['AUTHORIZATION_SYSTEM_ADMIN_GROUP'],
   );
   ```

   | Field | Default | Description |
   |---|---|---|
   | `grouper` | *(required)* | A `GrouperConfiguration`; see the [`guild/grouper` README](https://github.com/nathanskky/guild-grouper). |
   | `systemAdminGroup` | *(required)* | ACM label of the System Admin group. Members are allowed everything. |
   | `membershipTtl` | `900` | Seconds a user's Grouper membership is cached in the session. |
   | `staleCap` | `3600` | Seconds past the TTL cached membership may still be used while Grouper is unreachable. `0` disables. |
   | `grouperTimeout` | `10.0` | Seconds to wait for a Grouper response. |
   | `grouperConnectTimeout` | `3.0` | Seconds to wait for a connection to Grouper. |

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

With both a template engine and authorization configured, in either order, templates get `can()` and
`cannot()`. A permission can be named by its stored value:

```twig
{% if can('documents.update', document) %}…{% endif %}
```

```latte
{if can('documents.update', $document)}…{/if}
```

An unknown value throws `ConfigurationException` when the template renders.

### Failures

| Exception | Meaning | Typical response |
|---|---|---|
| `Exception\AuthorizationException` | `Gate::authorize()` denied an authenticated user | 403 |
| `Exception\AuthenticationRequiredException` | `Gate::authorize()` denied a guest; extends `AuthorizationException` | Redirect to login, or 403 |
| `Exception\AuthorizationUnavailableException` | Grouper is unreachable and no cached membership is recent enough | 503 |

`View::render()` rethrows `AuthorizationUnavailableException` raised inside a template unwrapped, under
both engines. The System Admin check never uses stale membership.

### Administration pages

`addAuthorization()` mounts pages at `/framework/authorization`, gated on the System Admin group, where
administrators register Grouper groups by ACM label, map them to roles, and create, edit and delete roles
and their grants. Under OIDC a guest is sent to log in; under CAS, protect `/framework` in the Apache
configuration. With `addRivet()` the pages use the application's `rvt_page` layout, and System Admins see
a **System settings** menu in its header; pass `adminMenu: null` to leave the header alone.

## Contributing

See [`AGENTS.md`](AGENTS.md) for the architecture, verification commands, known landmines and the branch
workflow.
