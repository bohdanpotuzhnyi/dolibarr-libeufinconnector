#!/usr/bin/env bash
set -euo pipefail

IMAGE_NAME="libeufinconnector-ci"
CONTAINER_NAME="libeufinconnector-ci-run"
SNAPSHOT_MODULE_DIR_IN_CONTAINER="/opt/libeufinconnector-src"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_SRC="${MODULE_SRC:-$(cd "${SCRIPT_DIR}/../.." && pwd)}"
MODULE_DIR_IN_CONTAINER="${MODULE_DIR_IN_CONTAINER:-/opt/libeufinconnector-src}"
USE_LOCAL_MODULE=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --local-module)
      USE_LOCAL_MODULE=1
      shift
      ;;
    --help|-h)
      echo "Usage: $0 [--local-module]"
      echo "  default: use module snapshot baked into image (${SNAPSHOT_MODULE_DIR_IN_CONTAINER})"
      echo "  --local-module: mount local module source from MODULE_SRC into container"
      exit 0
      ;;
    *)
      echo "Unknown argument: $1"
      exit 1
      ;;
  esac
done

PODMAN_NETWORK_WAS_SET=0
if [ "${PODMAN_NETWORK+x}" = "x" ]; then
  PODMAN_NETWORK_WAS_SET=1
fi
PODMAN_NETWORK="${PODMAN_NETWORK:-slirp4netns}"

IS_ROOTLESS_PODMAN="$(podman info --format '{{.Host.Security.Rootless}}' 2>/dev/null || echo false)"
PODMAN_NETWORK_ARGS=()
if [ "${IS_ROOTLESS_PODMAN}" != "true" ] && [ "${PODMAN_NETWORK_WAS_SET}" -ne 1 ]; then
  case "${PODMAN_NETWORK}" in
    slirp4netns|pasta)
      if podman network exists bridge >/dev/null 2>&1; then
        PODMAN_NETWORK="bridge"
      else
        echo "== Podman network 'bridge' is not available; using host network =="
        PODMAN_NETWORK="host"
      fi
      ;;
  esac
fi

if [ -n "${PODMAN_NETWORK}" ]; then
  PODMAN_NETWORK_ARGS=(--network="${PODMAN_NETWORK}")
fi

echo "== Building image ${IMAGE_NAME} =="
podman build \
  "${PODMAN_NETWORK_ARGS[@]}" \
  --build-arg DOLIBARR_BRANCH="${DOLIBARR_BRANCH:-22.0.3}" \
  -f "${SCRIPT_DIR}/Containerfile" \
  -t "${IMAGE_NAME}" \
  "${MODULE_SRC}"

MODULE_DIR_ENV="${SNAPSHOT_MODULE_DIR_IN_CONTAINER}"
PODMAN_MOUNT_ARGS=()
if [ "${USE_LOCAL_MODULE}" = "1" ]; then
  if [ ! -d "${MODULE_SRC}" ]; then
    echo "Local module source directory does not exist: ${MODULE_SRC}"
    exit 1
  fi
  MODULE_DIR_ENV="${MODULE_DIR_IN_CONTAINER}"
  PODMAN_MOUNT_ARGS=(-v "${MODULE_SRC}:${MODULE_DIR_IN_CONTAINER}:ro,z")
fi

echo "== Running tests in container =="
podman run --rm \
  --name "${CONTAINER_NAME}" \
  "${PODMAN_NETWORK_ARGS[@]}" \
  "${PODMAN_MOUNT_ARGS[@]}" \
  -e DOLIBARR_BRANCH="${DOLIBARR_BRANCH:-22.0.3}" \
  -e DB="${DB:-mysql}" \
  -e TRAVIS_PHP_VERSION="${TRAVIS_PHP_VERSION:-8.3}" \
  -e MYSQL_PORT="${MYSQL_PORT:-13306}" \
  -e MYSQL_PASSWORD="${MYSQL_PASSWORD:-password}" \
  -e MODULE_DIR="${MODULE_DIR_ENV}" \
  -e MODULE_NAME="libeufinconnector" \
  "${IMAGE_NAME}"
