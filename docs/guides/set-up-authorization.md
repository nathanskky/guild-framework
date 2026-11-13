# Set up authorization

> For app developers turning on permissions, roles and Grouper groups in a Guild app.

This guide shows you how to declare your app's permissions, configure Grouper and the System Admin
group, call `addAuthorization()` and create the framework's tables.

## Before you start

- Your app calls `addIlluminateDatabase()`, because grants live in your app's database.
- With IU Login, your app calls `addAuthentication()`, as in [Add sign-in](add-sign-in.md). With CAS, Apache protects the app.
- You have a Grouper service account, and the ACM label of the group whose members administer the app.

## Turn on authorization

1. Declare the permissions your app checks, as one string-backed enum implementing `Permission`:

   ```php
   namespace App\Authorization;

   use Guild\Framework\Authorization\Permission;

   enum AppPermission: string implements Permission
   {
       case ReportsView = 'reports.view';
       case ReportsEdit = 'reports.edit';
   }
   ```

   Each value is what a grant stores. Renaming a value leaves the grants that use it granting nothing.

2. Create `config/authorization.php`, naming the System Admin group by its ACM label:

   ```php
   use Guild\Framework\Authorization\AuthorizationConfiguration;

   return new AuthorizationConfiguration(
       systemAdminGroup: $_ENV['AUTHORIZATION_SYSTEM_ADMIN_GROUP'],
   );
   ```

3. Create `config/grouper.php`, with your Grouper service account:

   ```php
   use Guild\Framework\Authorization\Membership\GrouperDirectoryConfiguration;
   use Guild\Grouper\GrouperConfiguration;

   return new GrouperDirectoryConfiguration(
       grouper: new GrouperConfiguration(
           serviceUrl: $_ENV['GROUPER_SERVICE_URL'],
           username: $_ENV['GROUPER_USERNAME'],
           password: $_ENV['GROUPER_PASSWORD'],
       ),
   );
   ```

4. Call `addAuthorization()` in `config/app.php`, after `addAuthentication()`:

   ```php
   ->addAuthorization(IdentitySource::Oidc, AppPermission::class, policies: [ReportPolicy::class])
   ```

   Use `IdentitySource::Cas` if Apache `mod_auth_cas` signs people in. Leave `policies` out until you
   [write a policy](write-a-policy.md).

5. Create the framework's tables from your app's root, with the database reachable:

   ```bash
   vendor/bin/phinx -c vendor/guild/framework/phinx.php migrate -e framework
   ```

The app now starts with authorization. A missing or invalid config file stops it with
`ConfigurationException`, naming the file. Run the migration command again after each framework upgrade,
because a release can add migrations.

## Choose the cache and timeout settings

The defaults suit most apps: membership cached for 15 minutes, served stale for up to an hour while
Grouper is down, and a 10-second Grouper timeout. To change them, pass `membershipTtl` and `staleCap` to
`AuthorizationConfiguration`, or `timeout` and `connectTimeout` to `GrouperDirectoryConfiguration`. Their
docblocks describe each setting, and [Authorization](../concepts/authorization.md#membership-cache)
explains the cache.

For the Grouper stem and other connection settings, follow guild/grouper's
[Configure the client](https://github.com/nathanskky/guild-grouper/blob/main/docs/guides/configure-the-client.md).

## Place the administration menu

With `addRivet()`, members of the System Admin group see a **System settings** menu in the header. To
rename or move it, pass `adminMenu: new AdminMenu(label: 'Admin', position: 0)`. To leave the header
alone, pass `adminMenu: null`; the pages at `/framework/authorization` stay reachable either way.

## Protect the admin pages under CAS

Under IU Login, the framework asks a guest to sign in before the admin pages. Under CAS, protect
`/framework` in your Apache configuration, because the framework can't send anyone to sign in.

## Test with the framework's tables

To create the same tables in a test database, such as in-memory SQLite, call
`Guild\Framework\Testing\FrameworkSchema::migrate($connection)`.

## Related

- [Manage groups and roles](manage-groups-and-roles.md) sets up the first grants.
- [Check permissions](check-permissions.md) uses them in your code and templates.
- [Config files](../reference/config-files.md) lists every config file the framework reads.
