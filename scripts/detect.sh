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
SHOW_MODIFIED="${SHOW_MODIFIED:-false}"
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
mapfile -t changed_files < <(git diff "${BASE_REF}...HEAD" --diff-filter=AMD --name-only -- "${paths_array[@]}" || true)

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

# When install-dependencies is on, the action exports VENDOR_PATH as an
# absolute path to the project's vendor/. We append it to the source roots so
# better-reflection can walk through into vendor parents on both HEAD and BASE
# snapshots (BASE uses HEAD's vendor since dependencies aren't in git).
roots_for_snapshot=("${roots_array[@]}")
if [[ -n "${VENDOR_PATH:-}" && -d "${VENDOR_PATH}" ]]; then
    roots_for_snapshot+=("${VENDOR_PATH}")
    echo "Including vendor path for resolution: ${VENDOR_PATH}"
fi
roots_csv=$(IFS=','; echo "${roots_for_snapshot[*]}")

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
    --output="${OUTPUT_DIR}/head-snapshot.json"

# BASE snapshot — run from base-tree so relative paths match.
(
    cd "${base_tree}"
    php "${SCRIPT_DIR}/snapshot.php" \
        --files="${files_csv}" \
        --roots="${roots_csv}" \
        --output="${OLDPWD}/${OUTPUT_DIR}/base-snapshot.json"
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
