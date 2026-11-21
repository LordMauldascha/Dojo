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

Page: `/admin/users`. The list has the shared overview toolbar (search, entries per page, *New user*), sortable columns
(Username, Role, Status) and pagination, see [Overview tables](overview-tables.md). Search matches the username.

- **Create** a user with the *New user* button in the toolbar, which opens a dialog (it reopens with the errors after an
  invalid submit): username, password (typed twice) and role. The username has 3 to 64 characters from `a-z`,
  `0-9`, `.`, `_`, `-` and must be unique. The password has at least 12 characters (the console command applies the same rule).
- **Disable or enable** a user. A disabled user keeps all data but cannot sign in.
- **Delete** a user. Memberships in groups and projects are removed. Skills, testcases and runs the user created stay;
  their creator is then unknown, so only admins can delete them.
- Admins cannot disable or delete their own account.

## Own account

- `/account` shows the profile (username, role, account created, last sign-in), stat tiles (visible projects, group
  memberships, and the skills, testcases and runs the user created; deleted skills and testcases do not count), the
  user's groups, the same project table as the home page and a button that opens the password dialog. Group titles link to the
  group page for admins only.
- Account created and last sign-in are stored on the user (`createdAt`, `lastLoginAt`). Accounts that existed before the
  fields were added show "–" for the creation date. The last sign-in is the time of the last successful login (a session
  that is only refreshed does not count) and is visible to the user only, not in the admin user list. It is personal data:
  mention it in the record of processing activities.
- The password dialog (same native `<dialog>` as the delete confirmations) posts to `POST /account/password`: the current
  password is required, the new one has at least 12 characters and is typed twice. After an invalid submit the account
  page is shown again (status 422) and the dialog opens by itself with the errors. There is no separate password page. After five wrong current passwords within
  15 minutes the form refuses every further attempt for a while, even with the right password.

## Home page

`/` is the overview after login, see [Overview](overview.md).
