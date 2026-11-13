# Manage groups and roles

> For System Admin group members granting access in a Guild app's administration pages.

This guide shows you how to register a Grouper group, create a role with permissions, and map the group
to the role.

## Before you start

- The app has authorization set up, as in [Set up authorization](set-up-authorization.md).
- You're a member of the app's System Admin group.
- You know the ACM label of the group you want to grant access to.

## Grant a group access

1. Open `/framework/authorization`, or select **System settings** in the header.
2. Under roles, create a role, such as `Report editors`, and select its permissions.
3. Under groups, register the group by its ACM label. If several groups carry that label, choose one by its identifier.
4. On the group's page, select the roles it maps to, and save.

Members of the group get the role's permissions on their next request after their cached membership
expires. A change to a role's permissions applies on everyone's next request, because grants aren't
cached.

## Change or remove access

- To stop a group or role granting anything without deleting it, clear its **Active** box.
- To rename a group for this app, edit its name; the name is a local label, and the identifier stays the same.
- To remove a group from this app, open it and select **Unregister group**; the group in ACM isn't affected.
- To delete a role, open it and select **Delete this role**, then confirm.

Every change records your username as the person who made it.

## When the pages answer 503

If Grouper is unreachable, registering a group answers that Grouper is unavailable instead of reporting
the label as unknown. If an extension supplies the app's groups instead of Grouper, registering always
answers 503, and editing, mapping and unregistering still work.

If your own membership can't be confirmed, every admin page answers 503, because the System Admin check
never uses stale membership.

## Related

- [Authorization](../concepts/authorization.md) explains how grants combine with groups and policies.
