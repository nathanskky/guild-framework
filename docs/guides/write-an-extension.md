# Write an extension

> For package authors writing a Composer package that changes how Guild apps sign in, find groups or
> name their System Admin group.

This guide shows you how to declare an extension package, choose slots in `configure()`, bind your
classes in `boot()` and test the package.

## Before you start

- You've read [Extensions](../concepts/extensions.md), which explains discovery and slots.
- Your package requires `guild/framework`.

## Declare the package

In your package's `composer.json`, set the type to `guild-extension` and name your extension class:

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

An app that installs the package runs it with no change to `config/app.php`.

## Write the extension class

Implement `ApplicationExtension`. Choose slots in `configure()`, and bind what you chose in `boot()`:

```php
use Guild\Framework\Application;
use Guild\Framework\ApplicationBuilder;
use Guild\Framework\Extension\ApplicationExtension;

final class SsoExtension implements ApplicationExtension
{
    public function configure(ApplicationBuilder $builder): void
    {
        $builder->useSignInMethod(SsoSignIn::class);
    }

    public function boot(Application $app): void
    {
        $app->addShared(SsoSignIn::class, fn () => new SsoSignIn(/* … */));
    }
}
```

`configure()` runs before the app's own `add*()` calls; a choice made later is refused. `boot()` runs at
the end of `create()`. The container must be able to build every chosen class that's used, through your
binding or the app's `enableAutoWiring()`; otherwise `create()` throws.

To read a config file of your own beside the app's, find the directory with `$builder->path('config')`.

## Implement a slot

| Slot | Implement | Notes |
|---|---|---|
| `useSignInMethod()` | `SignInMethod` | The app must still call `addAuthentication()` and use `IdentitySource::Oidc` |
| `useGroupDirectory()` | `GroupDirectory` | Return Grouper system names, so the app's grants keep working |
| `useSystemAdminGroup()` | — | Pass the group's ACM label |

`SignInMethod` and `GroupDirectory` document each method's contract in their docblocks. The framework
calls `SignInMethod::challenge()` only for a guest.

## Protect your own forms

To add a form that changes data, render `Csrf::field()` inside it and put `VerifyCsrfToken` on its route.
If your sign-in method changes who is signed in, call `Csrf::rotate()`.

## Test the extension

- Boot an app with only your extension by passing it to `Application::configure($basePath, [new SsoExtension()])`.
- Extend `Testing\GroupDirectoryContract` once for your group directory; every directory must pass it.
- Use `Testing\ArraySessionStore` for the session, and `Testing\FrameworkSchema::migrate()` for the grant tables.

guild/dev-tools is a working extension that chooses all three slots.

## Related

- [Extensions](../concepts/extensions.md) explains the slot rules and logging.
