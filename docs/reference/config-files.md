# Config files

> For app developers checking which file each `ApplicationBuilder` call reads, and what the file returns.

Paths are relative to the base path given to `Application::configure()`. A call an app doesn't make
needs no file.

| Call | File | Returns |
|---|---|---|
| `addRouting()` | `routes/routes.php` | A callable that receives the `Router` |
| `addIlluminateDatabase()` | `config/database.php` | An Eloquent connection array |
| `addAuthentication()` | `config/authentication.php` | guild/access's `OidcConfiguration` |
| `addAuthorization()` | `config/authorization.php` | `AuthorizationConfiguration` |
| `addAuthorization()` | `config/grouper.php` | `GrouperDirectoryConfiguration` |
| `addTemplateEngine()` | None; templates load from `templates/` | — |
| `addRivet()` | None; pass guild/rivet's `PageDefaults` as the argument | — |
| `enableAutoWiring()` | None | — |
| `withLogger()` | None | — |

When an extension chooses the sign-in method, `config/authentication.php` isn't read. When it chooses
the group directory, `config/grouper.php` isn't read. When it chooses the System Admin group,
`systemAdminGroup` in `config/authorization.php` can be blank.

A missing or invalid file throws `ConfigurationException` at startup, naming the file, for every call
except `addRouting()`.

`addRouting()` doesn't wrap its file: a missing `routes/routes.php` fails as a plain `require` error.
If the file returns something that isn't callable, no routes are registered and every request gets a 404,
with no error.

The configuration classes document their fields in their docblocks.
