# API Surface Check

Detects public API surface changes in a pull request — added, removed, or modified classes, interfaces, traits, enums, methods, and constants — via static reflection (no code execution, no project dependencies required).

## How it works

For every modified PHP file the action:

1. Snapshots the file's symbols on the **head** ref using [`roave/better-reflection`](https://github.com/Roave/BetterReflection).
2. Snapshots the same files on the **base** ref (extracted with `git archive`).
3. Diffs the two snapshots. A method or constant is only reported on a class when its **introduction point** is the class itself — implementations of existing interface methods, overrides of parent methods, or members already declared on a parent in the unchanged code are not flagged.

The result is written to a directory containing `comment-body.txt` (a markdown body, possibly empty). A companion `post-comment` sub-action posts, updates, or deletes a PR comment based on that body.

## Why static reflection?

The action parses your code via `roave/better-reflection`'s AST-based reflection rather than `require`-ing it and using PHP's runtime reflection. Two consequences:

- **No code execution.** PR-controlled PHP is never loaded by the action — it's parsed as data. This is what makes the analysis safe to run against fork PRs.
- **Optional, sandboxed dependency install.** Resolving parents that live in Composer dependencies is opt-in (`install-dependencies: true`). When enabled, `composer install` runs with `--no-scripts --no-plugins` — composer fetches archives and writes autoload files, but never executes anything from a tarball or a configured plugin.

## Usage

The action is split across two workflows so that fork PRs can be analyzed safely. The trigger workflow runs in PR context with read-only permissions; the comment workflow runs in `workflow_run` context with `pull-requests: write` and never trusts artifact contents — it re-resolves the PR via the GitHub API and checks out the action's own code from your default branch.

### Trigger workflow (`pull_request`)

A minimal job whose only purpose is to fire `workflow_run` so the main workflow can pick up the job.

```yaml
name: 'API Surface Check'

# Minimal pull_request workflow whose only job is to fire workflow_run for the
# api-surface-comment workflow (which has pull-requests:write even on fork PRs
# and runs the actual analysis from trusted action code).

on:
  pull_request:
    paths-ignore:
      - 'doc/**'   # optional: skip doc-only PRs entirely
      - 'tests/**'

permissions: {}

# Cancel an in-progress trigger run when a new push lands on the same PR.
concurrency:
  group: api-surface-${{ github.event.pull_request.number }}
  cancel-in-progress: true

jobs:
  signal:
    name: 'Trigger check'
    runs-on: ubuntu-latest
    steps:
      - run: 'true'
```

### Comment workflow (`workflow_run`, has write permissions)

The main action handles the entire flow — downloading the preflight verdict, resolving the PR, checking out the PR head, running the analysis, and posting / updating / deleting the comment. The job body is one `uses:` line.

```yaml
name: 'API Surface Comment'
on:
  workflow_run:
    workflows: ['API Surface Check']
    types: [completed]

# Cancel an in-progress comment run when a force-push or workflow_run re-run
# fires for the same PR. head_sha alone wouldn't catch force-pushes (each push
# has a different SHA); the (head repo + head branch) pair survives that and
# disambiguates forks using identical branch names.
concurrency:
  group: api-surface-comment-${{ github.event.workflow_run.head_repository.full_name }}-${{ github.event.workflow_run.head_branch }}
  cancel-in-progress: true

jobs:
  check-api-surface:
    name: API surface comment
    runs-on: ubuntu-latest

    permissions:
      contents: read
      pull-requests: write # required to create the comment on the pull request

    steps:
      - uses: composer/api-surface-check@main # TODO pin the version here ideally
        with:
          # install-dependencies: false
          # paths: src/**/*.php
          # source-roots: src
          # include-internal: false
          # show-removed: true
          # show-modified: true
```

### Why two workflows

`pull_request` events from forks don't get write permission on the base repo's `GITHUB_TOKEN`, so the comment can't be posted from there. `workflow_run` runs in the base-repo context with elevated permissions but is fired by the (untrusted) `pull_request` workflow's completion. We treat anything from the PR as data, never as code or as identifiers — the action is loaded by GitHub from a known repo+ref (not from PR code), the PR head is checked out only as input to static reflection (better-reflection never executes it), and the PR number is resolved via the GitHub API from `workflow_run.head_repository.owner.login` and `workflow_run.head_branch` rather than read from the PR-side artifact.

## Inputs

### Paths

| Input                        | Default        | Description                                                                                                                                                  |
| ---------------------------- | -------------- |--------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `paths`                      | `src/**/*.php` | Pathspec patterns to analyze, whitespace or newline separated.                                                                                               |
| `source-roots`               | `src`          | Directories used to resolve parent classes/interfaces.                                                                                                       |
| `working-directory`          | `.`            | Directory the analysis runs from (must be a git repo). Useful for monorepos.                                                                                 |
| `composer-working-directory` | (empty)        | Directory containing the analyzed project's `composer.json`. Empty means: same as `working-directory`. Only consulted when `install-dependencies` is `true`. |

### Reporting

| Input                  | Default                                               | Description                                                                                                                                                                                                                          |
| ---------------------- | ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `install-dependencies` | `false`                                               | Run `composer install` in the analyzed project so vendor classes are reachable when the introduction-point walk crosses into a dependency. Composer is invoked with `--no-scripts --no-plugins` so PR-controlled code never executes. |
| `include-internal`     | `false`                                               | Include `@internal`/`@private` symbols in reports.                                                                                                                                                                                   |
| `types`                | `class,interface,trait,enum,method,property,constant` | Symbol kinds to report.                                                                                                                                                                                                              |
| `visibility`           | `public,protected`                                    | Visibilities to report.                                                                                                                                                                                                              |
| `show-removed`         | `true`                                                | Include removed symbols.                                                                                                                                                                                                             |
| `show-modified`        | `true`                                                | Include symbols whose signature changed.                                                                                                                                                                                             |
| `heading`              | `## API Surface Changes`                              | Markdown heading for the comment body.                                                                                                                                                                                               |

### Internal

| Input                | Default                    | Description                                                                            |
| -------------------- | -------------------------- | -------------------------------------------------------------------------------------- |
| `preflight-artifact` | `api-surface-preflight`    | Name of the artifact uploaded by the trigger workflow's preflight step.                |
| `output-dir`         | `api-surface-result`       | Directory inside the comment workflow's runner where the analysis output is written.   |
| `comment-marker`     | `<!-- api-surface-bot -->` | HTML marker used to identify previous bot comments.                                    |

The PR number and base ref are resolved automatically from the `workflow_run` event payload — you don't pass them.

## Resolving vendor parents

By default the action only resolves classes that live under `source-roots`. If your code extends classes that come from Composer dependencies (e.g. `class Foo extends \Symfony\…`), better-reflection can't follow those parents and the introduction-point check is incomplete — methods defined on a vendor parent may be wrongly flagged as new on your subclass.

Set `install-dependencies: 'true'` to have the action run `composer install --no-scripts --no-plugins --no-dev --prefer-dist --ignore-platform-reqs` in your project before snapshotting. The `--no-scripts` and `--no-plugins` flags are essential and non-negotiable: they reduce composer to "fetch tarballs and write autoload" with no PR-controlled code execution. better-reflection never loads or runs the vendor code itself — it's parsed statically.

The vendor tree is reused for both HEAD and BASE snapshots (BASE git archive doesn't contain vendor since it's gitignored). This means a PR that changes `composer.lock` in a way that adds or removes a method on a parent class won't be detected as an API surface change — that case is rare and arguably a dependency-management concern rather than an API surface one.

If `composer install` fails (network hiccup, auth issue, missing `composer.json`), the action emits a warning and proceeds without vendor resolution rather than aborting.

## Limitations

- Trait usage is not factored into the introduction-point check. A class that newly applies a trait will see the trait's methods reported as new on the class.
- Anonymous classes are skipped.
- Renaming a class (or moving it to another namespace) is reported as a removal of the old fully-qualified name plus an addition of the new one — there is no rename-pairing heuristic.
- When a parent class or implemented interface can't be resolved (no `install-dependencies`, or a private repository, or a transient install failure), the introduction-point check defaults to "introduced here" — i.e. methods will be reported even if they actually came from an unresolved parent. This is the conservative direction: rather over-report than miss real API additions.
