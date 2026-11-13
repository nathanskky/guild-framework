# Template functions

> For app developers looking up the functions the framework adds to Twig and Latte templates.

| Function | Needs | Returns |
|---|---|---|
| `can(permission, resource?)` | `addAuthorization()` | `true` if the current user may, as `Gate::allows()` |
| `cannot(permission, resource?)` | `addAuthorization()` | The opposite of `can()` |
| `current_user()` | `addAuthorization()` | The signed-in `User`, or `null` for a guest |
| `sign_out_form()` | `addAuthentication()` | The sign-out button and its CSRF-protected form, as HTML |

Each needs `addTemplateEngine()` as well, called before or after the call it depends on.

A permission is named by its value, such as `'reports.edit'`, or by an enum case. A value that isn't a
case of the app's permission enum throws `ConfigurationException` when the template renders.

```twig
{% if current_user() %}Signed in as {{ current_user().username }}{% endif %}
```

```latte
{if current_user()}Signed in as {current_user()->username()}{/if}
```

The Rivet components, such as `rvt_page`, come from guild/rivet; its
[components reference](https://github.com/nathanskky/guild-rivet/blob/main/docs/reference/components.md)
lists them.
