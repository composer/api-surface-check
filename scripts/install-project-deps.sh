#!/usr/bin/env bash
# Installs the analyzed project's dependencies so vendor parents can be resolved.
#
# Runs from the project directory (PR-controlled). composer.json/lock are copied
# into a fresh INSTALL_DIR with `config` (bar platform/policy), `autoload` and `scripts` stripped, so
# PR-controlled paths (vendor-dir, bin-dir, cache dirs, committed symlinks) can't
# make composer write outside INSTALL_DIR, e.g. into the action's own vendor/.
#
# Inputs (env):
#   INSTALL_DIR   Directory to install into; must not exist yet.
# Prints "project-path=<dir>" to GITHUB_OUTPUT (if set) on success.
set -euo pipefail

INSTALL_DIR="${INSTALL_DIR:?INSTALL_DIR is required}"

if [[ ! -f composer.json ]]; then
    echo "::warning::install-dependencies=true but no composer.json at $(pwd); proceeding without vendor resolution."
    exit 0
fi
for f in composer.json composer.lock; do
    if [[ -L "$f" ]]; then
        echo "::warning::${f} is a symlink; proceeding without vendor resolution."
        exit 0
    fi
done

mkdir "${INSTALL_DIR}"
INSTALL_DIR="$(cd "${INSTALL_DIR}" && pwd -P)"

# Keep only config keys that affect what gets installed, none of which are paths.
if ! jq '
    del(.autoload, ."autoload-dev", .scripts)
    | .config = (if (.config | type) == "object" then .config | with_entries(select(.key == "platform" or .key == "policy")) else {} end)
' composer.json > "${INSTALL_DIR}/composer.json"; then
    echo "::warning::composer.json could not be parsed; proceeding without vendor resolution."
    exit 0
fi
if [[ -f composer.lock ]]; then
    cp composer.lock "${INSTALL_DIR}/composer.lock"
fi

cd "${INSTALL_DIR}"
export COMPOSER_VENDOR_DIR="${INSTALL_DIR}/vendor"
export COMPOSER_BIN_DIR="${INSTALL_DIR}/vendor/bin"
unset COMPOSER

# --no-scripts blocks composer.json scripts; --no-plugins blocks installed
# composer-plugin code. Together with the isolated copy above, composer install
# reduces to "fetch tarballs and write autoload into INSTALL_DIR".
if ! composer install --no-scripts --no-plugins --no-dev --prefer-dist --no-interaction --no-progress --ignore-platform-reqs; then
    echo "::warning::composer install failed; proceeding without vendor resolution."
    exit 0
fi

if [[ -d vendor && -n "${GITHUB_OUTPUT:-}" ]]; then
    echo "project-path=${INSTALL_DIR}" >> "${GITHUB_OUTPUT}"
fi
