#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

cd "${REPO_ROOT}"

CURRENT_HOOKS_PATH="$(git config --get core.hooksPath || true)"
if [ -n "${CURRENT_HOOKS_PATH}" ] && [ "${CURRENT_HOOKS_PATH}" != ".githooks" ]; then
    echo "Overriding existing core.hooksPath for this repository: ${CURRENT_HOOKS_PATH}"
fi

git config --local core.hooksPath .githooks
chmod +x .githooks/pre-commit .githooks/pre-push

if ! command -v pre-commit >/dev/null 2>&1; then
    cat <<'EOF'
pre-commit is not installed.
Install it first, for example:
  pipx install pre-commit
or:
  python3 -m pip install --user pre-commit
EOF
    exit 0
fi

pre-commit install-hooks

echo "Git hooks installed for libeufinconnector."
