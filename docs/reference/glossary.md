# Glossary

> For app developers meeting a framework term in a Guild app's docs or code.

| Term | Meaning | In code |
|---|---|---|
| Permission | One thing your app checks, as a case of its string-backed permission enum. | `Authorization\Permission` |
| Role | A named set of permissions that Grouper groups map to. | `framework_roles`, `Model\Role` |
| Grant | A group mapped to a role, or a permission held by a role, set up at `/framework/authorization`. | `framework_groups_roles`, `framework_role_permissions` |
| Group | A Grouper group registered in the app, matched on its Grouper identifier; its name is a local label. guild/grouper's glossary defines Grouper's own groups. | `framework_groups`, `Model\Group` |
| Policy | A class whose methods decide which instances of one resource class a user may act on. | `#[HandlesResource]`, `#[Handles]` |
| System Admin group | The Grouper group, named by its ACM label, whose members administer the app and pass every check. | `AuthorizationConfiguration::$systemAdminGroup` |
| Membership cache | A user's Grouper groups, kept in the session for a set time and served stale for a capped time while Grouper is down. | `membershipTtl`, `staleCap` |
| Identity source | Where the signed-in user's identity comes from: IU Login or Apache CAS. | `Authorization\IdentitySource` |
| Sign-in method | How the app signs people in and out; IU Login unless an extension chose another. | `Authentication\SignInMethod` |
| Group directory | Where users' groups come from; Grouper unless an extension chose another. | `Authorization\Membership\GroupDirectory` |
| Extension | A Composer package of type `guild-extension` that the framework finds and runs at startup. | `Extension\ApplicationExtension` |
| Slot | A part of the app an extension can choose: the sign-in method, the group directory or the System Admin group. | `useSignInMethod()`, `useGroupDirectory()`, `useSystemAdminGroup()` |

guild/grouper's [glossary](https://github.com/nathanskky/guild-grouper/blob/main/docs/reference/glossary.md)
defines stem, ACM label and identifier. guild/dev-tools' [glossary](https://github.com/nathanskky/guild-dev-tools/blob/main/docs/reference/glossary.md)
defines persona and roster.
