#!/usr/bin/env bash
# Posts, updates, or deletes a PR comment based on the artifact produced by
# detect.sh. Designed to run from the workflow_run companion workflow that has
# pull-requests:write permission even for fork PRs.
set -euo pipefail

OUTPUT_DIR="${OUTPUT_DIR:-api-surface-result}"
COMMENT_MARKER="${COMMENT_MARKER:-<!-- api-surface-bot -->}"
GITHUB_REPOSITORY="${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
: "${GH_TOKEN:?GH_TOKEN is required}"

if [[ ! -f "${OUTPUT_DIR}/pr-number.txt" ]]; then
    echo "No pr-number.txt in ${OUTPUT_DIR}; nothing to do."
    exit 0
fi

PR_NUMBER=$(cat "${OUTPUT_DIR}/pr-number.txt")
COMMENT_BODY=""
if [[ -s "${OUTPUT_DIR}/comment-body.txt" ]]; then
    COMMENT_BODY=$(cat "${OUTPUT_DIR}/comment-body.txt")
fi

existing_comment_id=$(gh api "repos/${GITHUB_REPOSITORY}/issues/${PR_NUMBER}/comments" --paginate \
    -q ".[] | select(.body | contains(\"${COMMENT_MARKER}\")) | .id" | head -1) || true

if [[ -z "${COMMENT_BODY}" ]]; then
    if [[ -n "${existing_comment_id}" ]]; then
        gh api -X DELETE "repos/${GITHUB_REPOSITORY}/issues/comments/${existing_comment_id}" || true
        echo "Deleted stale API surface comment."
    else
        echo "No changes; nothing to comment."
    fi
    exit 0
fi

if [[ -n "${existing_comment_id}" ]]; then
    gh api -X PATCH "repos/${GITHUB_REPOSITORY}/issues/comments/${existing_comment_id}" -f body="${COMMENT_BODY}" > /dev/null
    echo "Updated API surface comment."
else
    gh api "repos/${GITHUB_REPOSITORY}/issues/${PR_NUMBER}/comments" -f body="${COMMENT_BODY}" > /dev/null
    echo "Posted API surface comment."
fi
