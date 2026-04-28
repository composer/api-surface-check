#!/usr/bin/env bash
# Preflight gate: scan the diff against BASE_REF for tokens that could affect
# the API surface. Writes "true" or "false" to OUTPUT_FILE.
#
# Bias is heavily toward "true" — false positives cost ~5s of wasted analysis,
# false negatives could mask a real API surface change.
#
# Inputs (env):
#   BASE_REF      Git ref to diff against (e.g. origin/main).
#   PATHS_GLOB    Pathspec patterns (whitespace or newline separated). Default: src/**/*.php
#   OUTPUT_FILE   Path to write the verdict to. Default: api-surface-preflight/preflight.txt
set -euo pipefail

BASE_REF="${BASE_REF:?BASE_REF is required}"
PATHS_GLOB="${PATHS_GLOB:-src/**/*.php}"
OUTPUT_FILE="${OUTPUT_FILE:-api-surface-preflight/preflight.txt}"

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

mkdir -p "$(dirname "${OUTPUT_FILE}")"

diff_output=$(git diff "${BASE_REF}...HEAD" --unified=0 -- "${paths_array[@]}" 2>/dev/null || true)

if [[ -z "${diff_output}" ]]; then
    echo "false" > "${OUTPUT_FILE}"
    echo "No changes in scoped paths."
    exit 0
fi

# Filter to actual added/removed code lines (exclude diff headers like +++ ---).
# Then look for tokens that could change the public API surface.
api_pattern='\b(class|interface|trait|enum|function|const|extends|implements|public|protected|private|abstract|final|static|readonly)\b|\buse\s+\w|@(internal|private)\b'

if echo "${diff_output}" | grep -E '^[+-][^+-]' | grep -qE "${api_pattern}"; then
    echo "true" > "${OUTPUT_FILE}"
    echo "Detected potential API-surface change; full analysis required."
else
    echo "false" > "${OUTPUT_FILE}"
    echo "No API-relevant tokens in the diff; downstream comment workflow will skip."
fi
