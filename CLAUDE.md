# Development workflow (mandatory)

Every code change follows the four steps below, including small fixes and one-line changes.
Find the default branch with: `gh repo view --json defaultBranchRef -q .defaultBranchRef.name`

## 1. Issue

- **Existing issue:** if the user refers to an issue ("pick up #42", "pick up this issue", an issue URL), work on THAT issue and do not create a new one. Read it first with `gh issue view <nr>`.
- **New work:** if no issue is given, create one first with `gh issue create --title "<title>" --body "<description>"` and tell the user the issue number.
- Before creating a branch, check whether the issue already has a linked branch: `gh issue develop --list <nr>`. If it has one, check out that branch and continue there instead of creating a new one.

## 2. Branch

- Always create the branch with GitHub's native "Create a branch" feature, so it is linked to the issue:
  ```
  git fetch origin
  gh issue develop <nr> --name "<nr>-<slug>" --base <default-branch> --checkout
  ```
- Branch name format: `{issue_number}-{hyphened_issue_title}`
  - `<slug>` is the issue title: lowercase, accents converted to plain letters (é → e, ü → u), every character that is not a-z or 0-9 replaced by a hyphen, repeated hyphens collapsed to one, no leading or trailing hyphens.
  - Example: issue #42 "Fix checkout: VAT rounding (NL)" → `42-fix-checkout-vat-rounding-nl`
- Never create branches with `git checkout -b`, `git switch -c` or `git branch <name>`.

## 3. Commit, push and open for review

- Never commit or push on the default branch.
- Push the branch: `git push -u origin <branch>`
- If a PR already exists for this branch (`gh pr view`), push to it and do not open a second one.
- Otherwise open a PR from the linked branch:
  ```
  gh pr create --base <default-branch> --title "<issue title>" --body ""
  ```
- Keep the PR body empty: no summary, explanation, test plan or `Closes #<nr>`. The linked branch already ties the PR to the issue.
- Report the PR URL to the user and stop there. The PR is now ready for review.

## 4. Squash merge

- Merge ONLY when the user explicitly asks for it. Never merge on your own initiative.
- Always squash merge and delete the branch, with a commit message you write yourself:
  ```
  gh pr merge <pr-nr> --squash --delete-branch --subject "<subject> (#<pr-nr>)" --body "<body>"
  ```
- Write the squash commit message for a marketing or client audience, not for developers:
  - The subject says what changed for the user or the shop in plain language, e.g. "Customers can now pay with iDEAL in checkout" instead of "Add iDEAL payment method handler".
  - The body is one or two short sentences on what is different and why it matters. Leave it empty if the subject already says it all.
  - No technical jargon, file names, function names, class names or ticket chatter.
  - Write in the language the client uses in the issue.
- Never use a merge commit or rebase merge.
- After merging, update locally: `git switch <default-branch> && git pull`
