# MCP server

An AI agent (for example Claude Code) can use Dojo through the Model Context Protocol. It acts as the person who
connected it and can do what that person can do in the web interface, nothing more. It cannot manage users, groups or the
[LLM configuration](llm-configuration.md) (keys, judge, Hugging Face models) and it cannot change passwords.

## Connect

```bash
claude mcp add --transport http dojo https://<dojo-host>/mcp
```

The account page shows the command with the right URL while no agent is connected. On the first call the client opens the browser:

1. The client registers itself (`POST /oauth/register`, dynamic client registration).
2. The browser opens `/authorize`. A person who is not signed in logs in with the normal Dojo login.
3. The consent page `/oauth/consent` names the agent and the account and asks to approve or deny. Approve only a
   connection you started yourself on your own computer.
4. The client exchanges the code (PKCE, `S256`) for an access token (1 hour) and a refresh token (30 days) and keeps
   refreshing it, so the browser step is needed once per agent and 30 days of inactivity.

Anyone who can reach `/oauth/register` can create a client, but a client only matters once a signed-in person approves it
on the consent page. Redirect URIs of a client must be `http://127.0.0.1:<port>/...` or `http://localhost:<port>/...`.
Web-based connectors (for example claude.ai) cannot register for that reason.

## Rights

| Account | What the agent can do |
| --- | --- |
| User | Everything a user does: see the projects they have access to, create and edit skills, testcases and runs in them, start runs, delete own items. See [Access control](access-control.md). |
| Admin | Additionally create, edit and delete projects, assign groups and users to projects, and see all projects, skills, testcases and runs. |

Every tool runs the same voters and access filters as the web pages. Something the person may not see answers
*Forbidden* (known uuid) or *not found* (unknown uuid). A user or token that was disabled or revoked is refused with
*401* on the next call.

## Tools

Everything is addressed by the uuids that the list tools return. Users are addressed by username.

| Area | Tools |
| --- | --- |
| Overview | `get_overview` |
| Projects | `list_projects`, `get_project`; admins: `create_project`, `update_project`, `delete_project`, `add_project_group`, `remove_project_group`, `add_project_user`, `remove_project_user` |
| Skills | `list_skills`, `get_skill`, `get_skill_version`, `create_skill`, `save_skill` (draft or final), `set_skill_projects`, `restore_skill_version`, `discard_skill_draft`, `delete_skill` |
| Testcases | `list_testcases`, `get_testcase`, `get_testcase_version`, `create_testcase`, `save_testcase`, `set_testcase_projects`, `restore_testcase_version`, `discard_testcase_draft`, `delete_testcase` |
| Runs | `list_runs`, `get_run` (with the start check), `create_run`, `update_run`, `list_models`, `add_run_configuration`, `remove_run_configuration`, `set_run_skills`, `set_run_testcases`, `start_run`, `delete_run` |
| Run versions | `get_run_version` (results, outputs only on request), `compare_run_versions` |

Input is validated like the forms (same constraints, same limits). Errors come back as tool errors with the message, so
the agent can correct itself. Tools that delete or start carry the MCP hints `destructiveHint` (and `openWorldHint` for
`start_run`), so clients can ask the person before they run them.

## Things to know

- **Costs**: `start_run` calls LLM providers and costs money, exactly like *Start* in the UI. The confirmation dialog
  with the number of calls does not exist for agents; `get_run` shows `llmCalls` and `judgeCalls` before the start.
  `start_run` with `withBaseline=true` starts a version without skills first and the version with skills right after
  it (see [Baseline](runs.md#baseline)), so it doubles the calls. It only works while `get_run` reports
  `baselineAvailable`; the answer then also has `baselineVersion`, and `llmCalls` and `judgeCalls` count both versions.
- **Deleting** skills and testcases hides them (history stays); deleting runs and projects is final.
- **Data protection**: tool results (skill and testcase text, run outputs, judge reasons) go to the LLM provider of the
  agent, not only the providers configured in Dojo. The same rule as everywhere applies: no personal data in free text.
  Tool results contain no usernames except for project members, which only admins get.
- **Rate limit**: every MCP request is a `POST`, so the limit of 120 changes per minute and user also counts reads
  (answer *429*).
- **Disconnect**: the account page lists the agents the person approved ("Connected agents") with a *Disconnect*
  button. It revokes that person's tokens for the agent; other people who approved the same agent stay connected.

## Operations

- Keys: `OAUTH_PASSPHRASE` and `OAUTH_ENCRYPTION_KEY` in `.env.local` or the secrets vault, then
  `bin/console league:oauth2-server:generate-keypair` (see [Commands](../Commands.md)). Changing the keys signs out
  every agent.
- `MCP_ALLOWED_HOSTS`: JSON list of the host names (without port) the endpoint answers to, for example
  `'["dojo.example.com"]'`. The default is `["localhost"]`; other hosts are refused (DNS rebinding protection).
- Behind a TLS terminating proxy set `TRUSTED_PROXIES`, otherwise the discovery documents contain `http://` URLs.
- Run `bin/console league:oauth2-server:clear-expired-tokens` daily from cron. Registered clients are never cleaned up.
- MCP sessions are stored as files in the cache directory (`mcp.servers.default.session`). With several app replicas
  switch the store to `cache` with a shared pool.
- The `/mcp` firewall (`security.yaml`) only accepts bearer tokens with the scope `mcp`; `/token`, `/oauth/register`
  and `/.well-known/*` are public by design.
