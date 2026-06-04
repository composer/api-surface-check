#!/usr/bin/env bash
# Detect API surface changes between BASE_REF and HEAD.
# Reads configuration from env vars (set by the action), produces an output
# directory containing comment-body.txt (markdown body, possibly empty) and
# the head/base snapshot JSON files (kept for debugging).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ACTION_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

BASE_REF="${BASE_REF:?BASE_REF is required}"
PATHS_GLOB="${PATHS_GLOB:-src/**/*.php}"
SOURCE_ROOTS="${SOURCE_ROOTS:-src}"
OUTPUT_DIR="${OUTPUT_DIR:-api-surface-result}"

INCLUDE_INTERNAL="${INCLUDE_INTERNAL:-false}"
TYPES="${TYPES:-class,interface,trait,enum,method,property,constant}"
VISIBILITY="${VISIBILITY:-public,protected}"
SHOW_REMOVED="${SHOW_REMOVED:-true}"
SHOW_MODIFIED="${SHOW_MODIFIED:-true}"
COMMENT_MARKER="${COMMENT_MARKER:-<!-- api-surface-bot -->}"
HEADING="${HEADING:-## API Surface Changes}"

# Convert newline / whitespace separated lists into bash arrays.
read -r -a raw_paths <<< "$(echo "${PATHS_GLOB}" | tr '\n' ' ')"
paths_array=()
for p in "${raw_paths[@]}"; do
    [[ -z "$p" ]] && continue
    # Apply git's glob magic so ** crosses path components.
    if [[ "$p" == :* ]]; then
        paths_array+=("$p")
    else
        paths_array+=(":(glob)$p")
    fi
done
read -r -a roots_array <<< "$(echo "${SOURCE_ROOTS}" | tr '\n' ' ' | tr ',' ' ')"

mkdir -p "${OUTPUT_DIR}"
: > "${OUTPUT_DIR}/comment-body.txt"

# All files in scope (added, modified, or deleted) — snapshot.php will silently
# ignore missing files, so we can pass the same list for both refs.
#
# --no-renames is essential: with git's default rename detection a renamed file
# (e.g. Version.php -> VersionRenamed.php) is reported as a single R entry, which
# --diff-filter=AMD drops entirely, so neither path would reach the snapshotter.
# Disabling rename detection decomposes a rename into a delete (old path) + add
# (new path) — both pass the AMD filter, so the old FQCN is reported as removed
# and the new FQCN as added (there is no rename-pairing).
mapfile -t changed_files < <(git diff "${BASE_REF}..HEAD" --no-renames --diff-filter=AMD --name-only -- "${paths_array[@]}" || true)

# Filter out empty entries
changed_files=("${changed_files[@]/#/}")
filtered_files=()
for f in "${changed_files[@]}"; do
    [[ -n "$f" ]] && filtered_files+=("$f")
done
changed_files=("${filtered_files[@]}")

if [[ ${#changed_files[@]} -eq 0 ]]; then
    echo "No source files changed; skipping API surface analysis."
    exit 0
fi

echo "Analyzing ${#changed_files[@]} changed file(s)."
files_csv=$(IFS=','; echo "${changed_files[*]}")

# When install-dependencies is on, the action exports COMPOSER_PROJECT_PATH
# pointing at a directory containing composer.json + vendor/composer/installed.json.
# Snapshotter then resolves vendor parents through composer's PSR-4 mappings
# (O(1) per FQCN) instead of recursive directory walks.
#
# VENDOR_PATH is supported as a fallback for environments / tests where vendor/
# is laid out without a real composer.json — it appends the directory to the
# source roots so DirectoriesSourceLocator can find classes (slow on real
# vendor trees; intended for synthetic fixtures).
roots_for_snapshot=("${roots_array[@]}")
if [[ -n "${VENDOR_PATH:-}" && -d "${VENDOR_PATH}" ]]; then
    roots_for_snapshot+=("${VENDOR_PATH}")
    echo "Including vendor path for resolution: ${VENDOR_PATH}"
fi
roots_csv=$(IFS=','; echo "${roots_for_snapshot[*]}")
composer_arg=()
if [[ -n "${COMPOSER_PROJECT_PATH:-}" && -d "${COMPOSER_PROJECT_PATH}" ]]; then
    composer_arg=("--composer-path=${COMPOSER_PROJECT_PATH}")
    echo "Using composer project path for vendor resolution: ${COMPOSER_PROJECT_PATH}"
fi

# Extract BASE source for parent/interface resolution and BASE-side reflection.
base_tree="${OUTPUT_DIR}/.base-tree"
rm -rf "${base_tree}"
mkdir -p "${base_tree}"
# git archive needs at least one pathspec — feed it the source roots.
git archive "${BASE_REF}" -- "${roots_array[@]}" 2>/dev/null | tar -x -C "${base_tree}" || {
    echo "Note: BASE ref had no files matching source roots."
}

# HEAD snapshot
php "${SCRIPT_DIR}/snapshot.php" \
    --files="${files_csv}" \
    --roots="${roots_csv}" \
    --output="${OUTPUT_DIR}/head-snapshot.json" \
    "${composer_arg[@]}"

# BASE snapshot — run from base-tree so relative paths match.
(
    cd "${base_tree}"
    php "${SCRIPT_DIR}/snapshot.php" \
        --files="${files_csv}" \
        --roots="${roots_csv}" \
        --output="${OLDPWD}/${OUTPUT_DIR}/base-snapshot.json" \
        "${composer_arg[@]}"
)

# Diff
diff_args=(
    "--head=${OUTPUT_DIR}/head-snapshot.json"
    "--base=${OUTPUT_DIR}/base-snapshot.json"
    "--output=${OUTPUT_DIR}/comment-body.txt"
    "--types=${TYPES}"
    "--visibility=${VISIBILITY}"
    "--show-removed=${SHOW_REMOVED}"
    "--show-modified=${SHOW_MODIFIED}"
    "--comment-marker=${COMMENT_MARKER}"
    "--heading=${HEADING}"
)
if [[ "${INCLUDE_INTERNAL}" == "true" ]]; then
    diff_args+=("--include-internal=true")
fi
if [[ -n "${GITHUB_REPOSITORY:-}" ]]; then
    diff_args+=("--repo=${GITHUB_REPOSITORY}")
fi
if [[ -n "${PR_NUMBER:-}" ]]; then
    diff_args+=("--pr-number=${PR_NUMBER}")
fi

php "${SCRIPT_DIR}/diff.php" "${diff_args[@]}"

# Clean up base tree (snapshots are still in OUTPUT_DIR).
rm -rf "${base_tree}"

if [[ -s "${OUTPUT_DIR}/comment-body.txt" ]]; then
    echo "Detected API surface changes; comment body written to ${OUTPUT_DIR}/comment-body.txt"
    if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
        cat "${OUTPUT_DIR}/comment-body.txt" >> "${GITHUB_STEP_SUMMARY}"
    fi
    if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
        echo "has-changes=true" >> "${GITHUB_OUTPUT}"
    fi
else
    echo "No reportable API surface changes."
    if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
        echo "has-changes=false" >> "${GITHUB_OUTPUT}"
    fi
fi

if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
    echo "output-dir=${OUTPUT_DIR}" >> "${GITHUB_OUTPUT}"
fi
