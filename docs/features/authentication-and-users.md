# Authentication and users

## Login

Users sign in at `/login` with username and password. After five failed attempts the login is throttled for a while.
A disabled account cannot sign in; the message "This account is disabled." only appears after the correct password, so
nobody can probe for disabled accounts. The login form and the logout link are protected by a CSRF token.

Passwords are stored hashed (Symfony `auto` hasher). The hash itself is not kept in the session; the session only holds
a checksum, so changing a password, changing a role or disabling the account ends sessions that are already open.

## Roles

| Role | Label | Can |
| --- | --- | --- |
| `ROLE_USER` | User | Work in the projects they have access to |
| `ROLE_ADMIN` | Admin | Everything, including user, group, project and API key management |

`ROLE_ADMIN` includes `ROLE_USER`. There is no admin group: the role itself is the admin permission, also for admins that
are members of groups. There is no built-in admin account. Create the first one with
`bin/console app:user:create <username> --admin` (see [Commands.md](../Commands.md)).

## User management (admins)

Page: `/admin/users`.

- **Create** a user with username, password (typed twice) and role. The username has 3 to 64 characters from `a-z`,
  `0-9`, `.`, `_`, `-` and must be unique. The password has at least 12 characters (the console command applies the same rule).
- **Disable or enable** a user. A disabled user keeps all data but cannot sign in.
- **Delete** a user. Memberships in groups and projects are removed. Skills, testcases and runs the user created stay;
  their creator is then unknown, so only admins can delete them.
- Admins cannot disable or delete their own account.

## Own account

- `/account` shows who is signed in and links to the password form.
- `/account/password`: the current password is required, the new one has at least 12 characters and is typed twice.

## Home page

`/` is the overview after login, see [Overview](overview.md).
