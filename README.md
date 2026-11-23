# API Surface Check

Detects public API surface changes in a pull request (added, removed, or modified classes, interfaces, traits, enums, methods, and constants) via static reflection, and comments them on the PR.

## How it works

For every modified PHP file the action:

1. Snapshots the file's symbols on the **head** ref using [`roave/better-reflection`](https://github.com/Roave/BetterReflection).
2. Snapshots the same files on the **base** ref (the merge base, extracted with `git archive`).
3. Diffs the two snapshots. A method or constant is only reported on a class when its **introduction point** is the class itself — implementations of existing interface methods, overrides of parent methods, or members already declared on a parent in the unchanged code are not flagged.

The work is split in two actions, run from two workflows:

- **`composer/api-surface-check`** analyzes the PR in a `pull_request` workflow, which only gets a read-only token, and uploads the resulting comment text as an artifact.
- **`composer/api-surface-check/post-comment`** runs in a `workflow_run` workflow, which has `pull-requests: write` even for fork PRs. It downloads that artifact and posts, updates, or deletes the PR comment. It never checks out or executes anything from the PR.

## Why two workflows?

`pull_request` runs from forks don't get a token that can comment on the base repository. `workflow_run` does, but anything running there next to that token must not touch PR-controlled code. So the analysis — which checks out the PR and optionally installs its Composer dependencies — stays in the unprivileged `pull_request` run, and only plain text crosses over.

The comment job treats the artifact as untrusted data: it resolves the PR via the GitHub API from the triggering run, checks that the artifact's PR number and commit match it (and that the PR head has not moved since), only accepts a body that starts with the comment marker, caps its size, and only ever edits or deletes comments posted by `github-actions[bot]`.

A PR can still make the analysis report whatever it likes (it controls its own `pull_request` run), so the comment text should be read as a convenience, not as a trusted check.

## Usage

### Analysis workflow (`pull_request`)

```yaml
name: 'API Surface Check'

on:
  pull_request:
    paths-ignore:
      - 'doc/**'   # optional: skip doc-only PRs entirely
      - 'tests/**'

permissions:
  contents: read

# Cancel an in-progress run when a new push lands on the same PR.
concurrency:
  group: api-surface-${{ github.event.pull_request.number }}
  cancel-in-progress: true

jobs:
  analyze:
    name: 'Analyze API surface'
    runs-on: ubuntu-latest
    steps:
      - uses: composer/api-surface-check@2.0.0 # ideally pin the commit SHA instead
        with:
          # install-dependencies: false
          # paths: src/**/*.php
          # source-roots: src
          # include-internal: false
          # show-removed: true
          # show-modified: true
```

### Comment workflow (`workflow_run`)

```yaml
name: 'API Surface Comment'

on:
  workflow_run:
    workflows: ['API Surface Check']
    types: [completed]

# The (head repo + head branch) pair survives force-pushes and disambiguates
# forks using identical branch names.
concurrency:
  group: api-surface-comment-${{ github.event.workflow_run.head_repository.full_name }}-${{ github.event.workflow_run.head_branch }}
  cancel-in-progress: true

jobs:
  comment:
    name: 'API surface comment'
    runs-on: ubuntu-latest

    permissions:
      actions: read        # download the artifact from the analysis run
      pull-requests: write # create the comment on the pull request

    steps:
      - uses: composer/api-surface-check/post-comment@2.0.0 # ideally pin the commit SHA instead
```

`post-comment` only acts when the triggering run was a `pull_request` run that succeeded. A failed or cancelled analysis leaves any existing comment as is. Keep the analysis in its own workflow as shown above: if it shares a workflow with other jobs, any of them failing also blocks the comment update.

## Inputs

### `composer/api-surface-check`

#### Paths

| Input                        | Default        | Description                                                                                  |
| ---------------------------- | -------------- | -------------------------------------------------------------------------------------------- |
| `paths`                      | `src/**/*.php` | Pathspec patterns to analyze, whitespace or newline separated.                               |
| `source-roots`               | `src`          | Directories used to resolve parent classes/interfaces.                                       |
| `working-directory`          | `.`            | Directory the analysis runs from (must be a git repo). Useful for monorepos.                 |
| `composer-working-directory` | (empty)        | Directory containing the analyzed project's `composer.json`. Empty means: same as `working-directory`. |

#### Setup

| Input                  | Default                                                    | Description                                                                                                       |
| ---------------------- | ---------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `checkout`             | `true`                                                     | Check out the PR head. Set to `false` to check out (and optionally install dependencies) yourself before the action. |
| `install-dependencies` | `false`                                                    | Run `composer install` so vendor parents can be resolved, see [Resolving vendor parents](#resolving-vendor-parents). |
| `install-flags`        | `--no-dev --no-scripts --no-plugins --ignore-platform-reqs` | Flags passed to `composer install` (in addition to `--no-interaction --no-progress --prefer-dist`).                |

#### Reporting

| Input              | Default                                               | Description                                        |
| ------------------ | ----------------------------------------------------- | -------------------------------------------------- |
| `include-internal` | `false`                                               | Include `@internal`/`@private` symbols in reports. |
| `types`            | `class,interface,trait,enum,method,property,constant` | Symbol kinds to report.                            |
| `visibility`       | `public,protected`                                    | Visibilities to report.                            |
| `show-removed`     | `true`                                                | Include removed symbols.                           |
| `show-modified`    | `true`                                                | Include symbols whose signature changed.           |
| `heading`          | `## API Surface Changes`                              | Markdown heading for the comment body.             |

#### Shared with `post-comment`

These must have the same value in both actions.

| Input            | Default                    | Description                                         |
| ---------------- | -------------------------- | --------------------------------------------------- |
| `artifact-name`  | `api-surface-result`       | Name of the artifact carrying the result.           |
| `comment-marker` | `<!-- api-surface-bot -->` | HTML marker used to identify previous bot comments. |

### `composer/api-surface-check/post-comment`

Takes only `artifact-name` and `comment-marker` (see above). The PR is resolved from the `workflow_run` event payload — you don't pass it.

## Resolving vendor parents

By default the action only resolves classes that live under `source-roots`. If your code extends classes that come from Composer dependencies (e.g. `class Foo extends \Symfony\…`), better-reflection can't follow those parents and the introduction-point check is incomplete — methods defined on a vendor parent may be wrongly flagged as new on your subclass.

Set `install-dependencies: 'true'` to have the action run `composer install` in your project before snapshotting, using `install-flags`. better-reflection never loads or runs the vendor code itself — it's parsed statically.

If you need more control (private repositories, auth, a custom PHP setup), set `checkout: 'false'`, then check out the PR and run `composer install` yourself before the action. If a `vendor/composer/installed.json` is present, it is used even with `install-dependencies` off. Note that `actions/checkout` defaults to the PR merge commit, which works fine too.

The vendor tree is reused for both HEAD and BASE snapshots (BASE git archive doesn't contain vendor since it's gitignored). This means a PR that changes `composer.lock` in a way that adds or removes a method on a parent class won't be detected as an API surface change — that case is rare and arguably a dependency-management concern rather than an API surface one.

If `composer install` fails (network hiccup, auth issue, missing `composer.json`), the action emits a warning and proceeds without vendor resolution rather than aborting.

## Upgrading from 1.x

In 1.x a single action ran everything from the `workflow_run` workflow. In 2.x:

1. In the `pull_request` workflow, replace the no-op job with `composer/api-surface-check@2.0.0`, and set `permissions: contents: read`. Move your analysis inputs (`paths`, `install-dependencies`, …) there.
2. In the `workflow_run` workflow, use `composer/api-surface-check/post-comment@2.0.0` instead, and change its permissions to `actions: read` + `pull-requests: write` (`contents: read` is no longer needed).
3. If you customized `comment-marker`, set it in both.
4. `output-dir` was removed. The analysis output now lives in the runner's temp directory and is attached as the `artifact-name` artifact.
5. `install-dependencies` now installs in place with `install-flags`, rather than in an isolated copy, so relative `path` repositories work again.

Running 2.x of `composer/api-surface-check` from anything but a `pull_request` workflow fails with an error pointing here.

## Limitations

- Trait usage is not factored into the introduction-point check. A class that newly applies a trait will see the trait's methods reported as new on the class.
- Anonymous classes are skipped.
- Renaming a class (or moving it to another namespace) is reported as a removal of the old fully-qualified name plus an addition of the new one — there is no rename-pairing heuristic.
- When a parent class or implemented interface can't be resolved (no `install-dependencies`, or a private repository, or a transient install failure), the introduction-point check defaults to "introduced here" — i.e. methods will be reported even if they actually came from an unresolved parent. This is the conservative direction: rather over-report than miss real API additions.
