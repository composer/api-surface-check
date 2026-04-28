# API Surface Check

Detects public API surface changes in a pull request — added, removed, or modified classes, interfaces, traits, enums, methods, and constants — via static reflection (no code execution, no project dependencies required).

## How it works

For every modified PHP file the action:

1. Snapshots the file's symbols on the **head** ref using [`roave/better-reflection`](https://github.com/Roave/BetterReflection).
2. Snapshots the same files on the **base** ref (extracted with `git archive`).
3. Diffs the two snapshots. A method or constant is only reported on a class when its **introduction point** is the class itself — implementations of existing interface methods, overrides of parent methods, or members already declared on a parent in the unchanged code are not flagged.

The result is written to a directory containing `comment-body.txt` (a markdown body, possibly empty) and `pr-number.txt`. A companion `scripts/post-comment.sh` posts/updates/deletes a PR comment based on that artifact.

## Why static reflection?

The action parses your code via `roave/better-reflection`'s AST-based reflection rather than `require`-ing it and using PHP's runtime reflection. Two consequences:

- **No code execution.** PR-controlled PHP is never loaded by the action — it's parsed as data. This is what makes the analysis safe to run against fork PRs.
- **Optional, sandboxed dependency install.** Resolving parents that live in Composer dependencies is opt-in (`install-dependencies: true`). When enabled, `composer install` runs with `--no-scripts --no-plugins` — composer fetches archives and writes autoload files, but never executes anything from a tarball or a configured plugin.

## Usage

The action is split across two workflows so that fork PRs can be analyzed safely. The trigger workflow runs in PR context with read-only permissions; the comment workflow runs in `workflow_run` context with `pull-requests: write` and never trusts artifact contents — it re-resolves the PR via the GitHub API and checks out the action's own code from your default branch.

### Trigger workflow (`pull_request`)

```yaml
name: 'API Surface Check'
on:
  pull_request:

permissions:
  contents: read

jobs:
  check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v6
        with:
          fetch-depth: 0
          persist-credentials: false

      - uses: composer/api-surface-check/preflight@main
        with:
          base-ref: origin/${{ github.event.pull_request.base.ref }}

      - uses: actions/upload-artifact@v4
        with:
          name: api-surface-preflight
          path: api-surface-preflight/
          retention-days: 1
```

### Comment workflow (`workflow_run`, has write permissions)

```yaml
name: 'API Surface Comment'
on:
  workflow_run:
    workflows: ['API Surface Check']
    types: [completed]

permissions:
  contents: read
  pull-requests: write

jobs:
  comment:
    if: github.event.workflow_run.event == 'pull_request'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/download-artifact@v4
        with:
          name: api-surface-preflight
          path: api-surface-preflight/
          run-id: ${{ github.event.workflow_run.id }}
          github-token: ${{ github.token }}

      - id: preflight
        run: |
          if [[ "$(cat api-surface-preflight/preflight.txt 2>/dev/null)" == "true" ]]; then
              echo "should-run=true" >> "$GITHUB_OUTPUT"
          fi

      - id: pr
        if: steps.preflight.outputs.should-run == 'true'
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          response=$(gh api "repos/${{ github.repository }}/commits/${{ github.event.workflow_run.head_sha }}/pulls")
          number=$(echo "$response" | jq -r '.[0].number // empty')
          base_ref=$(echo "$response" | jq -r '.[0].base.ref // empty')
          [[ -n "$number" && -n "$base_ref" ]] || exit 0
          echo "number=$number" >> "$GITHUB_OUTPUT"
          echo "base-ref=$base_ref" >> "$GITHUB_OUTPUT"

      - if: steps.pr.outputs.number
        uses: actions/checkout@v6
        with:
          ref: refs/pull/${{ steps.pr.outputs.number }}/head
          fetch-depth: 0
          persist-credentials: false

      - if: steps.pr.outputs.number
        run: git fetch --no-tags origin "${{ steps.pr.outputs.base-ref }}:refs/remotes/origin/${{ steps.pr.outputs.base-ref }}"

      - if: steps.pr.outputs.number
        uses: composer/api-surface-check@main
        with:
          base-ref: origin/${{ steps.pr.outputs.base-ref }}
          pr-number: ${{ steps.pr.outputs.number }}
          # INPUTS / CONFIG GOES HERE
          # install-dependencies: true
          # paths: src/**/*.php
          # source-roots: src
          # include-internal: false
          # show-removed: true
          # show-modified: true

      - if: steps.pr.outputs.number
        env:
          GH_TOKEN: ${{ github.token }}
        uses: composer/api-surface-check/post-comment@main
```

### Why two workflows

`pull_request` events from forks don't get write permission on the base repo's `GITHUB_TOKEN`, so the comment can't be posted from there. `workflow_run` runs in the base-repo context with elevated permissions but is fired by the (untrusted) `pull_request` workflow's completion. We treat anything from the PR as data, never as code or as identifiers — the action's source is checked out from the default branch (`./.trusted`), the PR head is checked out only as input to static reflection (better-reflection never executes it), and the PR number is resolved through `gh api .../commits/<head_sha>/pulls` rather than read from the PR-side artifact.

## Inputs

### Paths

| Input                        | Default        | Description                                                                                                                                                    |
| ---------------------------- | -------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `paths`                      | `src/**/*.php` | Pathspec patterns to analyze, whitespace or newline separated.                                                                                                 |
| `source-roots`               | `src`          | Directories used to resolve parent classes/interfaces.                                                                                                         |
| `working-directory`          | `.`            | Directory the analysis runs from (must be a git repo). Useful for monorepos.                                                                                   |
| `composer-working-directory` | (empty)        | Directory containing the analyzed project's `composer.json`. Empty means: same as `working-directory`. Only consulted when `install-dependencies` is `true`.   |

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

| Input            | Default                    | Description                                                  |
| ---------------- | -------------------------- | ------------------------------------------------------------ |
| `base-ref`       | _required_                 | Git ref to compare HEAD against (e.g. `origin/main`).        |
| `pr-number`      | (empty)                    | Saved alongside the comment body for the comment workflow.  |
| `output-dir`     | `api-surface-result`       | Directory the artifact is written to.                       |
| `comment-marker` | `<!-- api-surface-bot -->` | HTML marker used to identify previous bot comments.         |

## Outputs

| Output        | Description                                                       |
| ------------- | ----------------------------------------------------------------- |
| `has-changes` | `true`/`false` — whether any reportable changes were detected.    |
| `output-dir`  | Path to the directory containing the artifact.                    |

## Sub-actions

Two helpers ship alongside the main action — both are tiny composite actions that wrap the bash scripts under `scripts/`. They keep the consumer workflows free of inline bash and let other projects pick up the same primitives.

### `composer/api-surface-check/preflight`

Quickly scans the PR diff for tokens that could affect the API surface. Writes `true` or `false` to a verdict file. Use it in the trigger workflow to skip the artifact upload (and downstream comment work) when nothing relevant changed.

| Input         | Default                                  | Description                                                |
| ------------- | ---------------------------------------- | ---------------------------------------------------------- |
| `base-ref`    | _required_                               | Git ref to diff against.                                   |
| `paths`       | `src/**/*.php`                           | Pathspec patterns to scan.                                 |
| `output-file` | `api-surface-preflight/preflight.txt`    | Where to write the `true`/`false` verdict.                 |

### `composer/api-surface-check/post-comment`

Posts, updates, or deletes a PR comment based on the artifact produced by the main action. Use it in the comment workflow.

| Input            | Default                              | Description                                              |
| ---------------- | ------------------------------------ | -------------------------------------------------------- |
| `output-dir`     | `api-surface-result`                 | Directory containing `comment-body.txt`/`pr-number.txt`. |
| `comment-marker` | `<!-- api-surface-bot -->`           | HTML marker that identifies previous bot comments.       |

Requires `GH_TOKEN` env (passed in by the consumer) with `pull-requests: write`.

## Resolving vendor parents

By default the action only resolves classes that live under `source-roots`. If your code extends classes that come from Composer dependencies (e.g. `class Foo extends \Symfony\…`), better-reflection can't follow those parents and the introduction-point check is incomplete — methods defined on a vendor parent may be wrongly flagged as new on your subclass.

Set `install-dependencies: 'true'` to have the action run `composer install --no-scripts --no-plugins --no-dev --prefer-dist --ignore-platform-reqs` in your project before snapshotting. The `--no-scripts` and `--no-plugins` flags are essential and non-negotiable: they reduce composer to "fetch tarballs and write autoload" with no PR-controlled code execution. better-reflection never loads or runs the vendor code itself — it's parsed statically.

The vendor tree is reused for both HEAD and BASE snapshots (BASE git archive doesn't contain vendor since it's gitignored). This means a PR that changes `composer.lock` in a way that adds or removes a method on a parent class won't be detected as an API surface change — that case is rare and arguably a dependency-management concern rather than an API surface one.

If `composer install` fails (network hiccup, auth issue, missing `composer.json`), the action emits a warning and proceeds without vendor resolution rather than aborting.

## Limitations

- Trait usage is not factored into the introduction-point check. A class that newly applies a trait will see the trait's methods reported as new on the class.
- Anonymous classes are skipped.
- When a parent class or implemented interface can't be resolved (no `install-dependencies`, or a private repository, or a transient install failure), the introduction-point check defaults to "introduced here" — i.e. methods will be reported even if they actually came from an unresolved parent. This is the conservative direction: rather over-report than miss real API additions.
