# Versioning

[Skills](skills.md) and [testcases](testcases.md) keep a full history of their content. Which fields are versioned is
listed on their pages; assignments to projects are not versioned.

## Rules

- Creating stores version 1 as a final version.
- **Save as draft** creates version *n+1* as a draft, shown as `2-draft`. Saving the draft again overwrites it; it stays
  `2-draft`.
- **Save as final** turns the draft into the final version with its number (`2-draft` becomes `2`). Without a draft it
  creates the next final version directly.
- There is exactly **one draft** per skill or testcase, shared by everybody (not one per user). If two people edit, the
  last save wins.
- The **active version** is the latest final version, even if a newer draft exists. Lists, search and runs use the active
  version and ignore drafts.
- **Discard draft** deletes the draft. The active version does not change; the next draft gets the next number again.
- **Restore** copies an old final version as a new final version. The history is kept, nothing is rewritten. Restore is
  blocked while a draft exists: save the draft as final or discard it first. A draft itself cannot be restored.

Final versions are never changed after they are saved. This is what lets [run versions](runs.md#versions) reference them
safely.

## Example

| Action | Result | Active version |
| --- | --- | --- |
| Create | `1` | `1` |
| Save as draft | `2-draft` | `1` |
| Save as draft again | `2-draft` (overwritten) | `1` |
| Save as final | `2` | `2` |
| Save as draft | `3-draft` | `2` |
| Discard draft | no draft | `2` |
| Restore `1` | new `3`, same content as `1` | `3` |

## In the interface

The detail page has two buttons, *Save as draft* and *Save as final*, plus *Discard draft* when a draft exists. Pressing
Enter in a text field submits nothing, so a version is only ever saved with one of the two buttons. The edit
form always starts from the draft if there is one, else from the active version. The version history lists every version
with its status (`active`, `draft`), a *View* link and a *Restore* button where restore is allowed.

## Guarantees in the database

Version numbers are unique per skill or testcase, and a partial unique index allows only one draft per skill or
testcase. The rules themselves live in the entities `Skill` and `Testcase`.
