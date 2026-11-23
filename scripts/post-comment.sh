#!/usr/bin/env bash
# Posts, updates, or deletes the PR comment from the analysis artifact.
# The artifact comes from a PR-controlled run: it is validated and only ever
# used as comment text, never executed.
#
# Inputs (env):
#   ARTIFACT_DIR    Directory holding comment-body.txt and metadata.json.
#   COMMENT_MARKER  Marker that starts every bot comment.
#   PR_NUMBER       PR number resolved via the API.
#   PR_HEAD_SHA     Current head SHA of that PR, resolved via the API.
#   RUN_HEAD_SHA    Head SHA of the triggering workflow run.
#   GH_TOKEN, GITHUB_REPOSITORY
set -euo pipefail

ARTIFACT_DIR="${ARTIFACT_DIR:?ARTIFACT_DIR is required}"
COMMENT_MARKER="${COMMENT_MARKER:-<!-- api-surface-bot -->}"
GITHUB_REPOSITORY="${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
PR_NUMBER="${PR_NUMBER:?PR_NUMBER is required}"
PR_HEAD_SHA="${PR_HEAD_SHA:?PR_HEAD_SHA is required}"
RUN_HEAD_SHA="${RUN_HEAD_SHA:?RUN_HEAD_SHA is required}"
: "${GH_TOKEN:?GH_TOKEN is required}"

# GitHub rejects comments over 65536 characters.
MAX_BODY_BYTES=65000

body_file="${ARTIFACT_DIR}/comment-body.txt"
meta_file="${ARTIFACT_DIR}/metadata.json"

if [[ ! -f "${body_file}" || ! -f "${meta_file}" || -L "${body_file}" || -L "${meta_file}" ]]; then
    echo "::warning::Artifact is missing comment-body.txt or metadata.json; nothing to do."
    exit 0
fi

meta_pr=$(jq -r '."pr-number" // empty | tostring' "${meta_file}" 2>/dev/null || true)
meta_sha=$(jq -r '."head-sha" // empty | strings' "${meta_file}" 2>/dev/null || true)

if [[ "${meta_pr}" != "${PR_NUMBER}" ]]; then
    echo "::warning::Artifact is for PR '${meta_pr}', expected ${PR_NUMBER}; nothing to do."
    exit 0
fi
if [[ "${meta_sha}" != "${RUN_HEAD_SHA}" ]]; then
    echo "::warning::Artifact is for commit '${meta_sha}', expected ${RUN_HEAD_SHA}; nothing to do."
    exit 0
fi
# A newer push has its own run coming; don't overwrite with a stale result.
if [[ "${meta_sha}" != "${PR_HEAD_SHA}" ]]; then
    echo "PR head moved to ${PR_HEAD_SHA} since ${meta_sha} was analyzed; nothing to do."
    exit 0
fi

COMMENT_BODY=""
if [[ -s "${body_file}" ]]; then
    if [[ "$(head -c "${#COMMENT_MARKER}" "${body_file}")" != "${COMMENT_MARKER}" ]]; then
        echo "::warning::Comment body does not start with the comment marker; nothing to do."
        exit 0
    fi
    if (( $(wc -c < "${body_file}") > MAX_BODY_BYTES )); then
        # Cut on a line boundary so no multi-byte character is split.
        COMMENT_BODY="$(head -c "${MAX_BODY_BYTES}" "${body_file}" | sed '$d')"$'\n\n_Output truncated, see the analysis job summary for the full list._'
    else
        COMMENT_BODY=$(cat "${body_file}")
    fi
fi

# Only consider comments posted with github.token, so other users' comments
# containing the marker are never edited or deleted.
COMMENT_AUTHOR="${COMMENT_AUTHOR:-github-actions[bot]}"
existing_comment_id=$(gh api "repos/${GITHUB_REPOSITORY}/issues/${PR_NUMBER}/comments" --paginate \
    | jq -r --arg author "${COMMENT_AUTHOR}" --arg marker "${COMMENT_MARKER}" \
        '.[] | select(.user.login == $author and (.body | startswith($marker))) | .id' | head -1) || true

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
