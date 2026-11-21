# Groups

A group is a named set of users. Groups are assigned to [projects](projects.md) to give all their members access at
once. Only admins see and manage groups.

## Data

- **Title**: required, at most 100 characters, unique regardless of letter case (`Team` and `team` are the same title).
- **Users**: any number of users of any role. Admins can be members too; the admin role always overrides what a group
  would grant.
- Created and last updated time.

There is no description, icon or color.

## Pages (admins)

| Page | URL | Purpose |
| --- | --- | --- |
| Overview | `/admin/groups` | Table with search on the title; columns Title, Users, Updated, Created |
| New group | `/admin/groups/new` | Create a group, then continue on its page |
| Group | `/admin/groups/{uuid}` | Rename, add members (select of users that are not members yet), remove members |

The overview follows the shared rules in [Overview tables](overview-tables.md).

## Deleting a group

The *Delete* button on the group page opens a confirmation dialog that lists the projects the group will be removed from. After confirming, the group, its memberships
and its project assignments are deleted. Users and projects stay. Members lose the project access they only had through
this group.

Deleting a user removes them from all groups.
