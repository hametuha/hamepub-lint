#!/usr/bin/env bash
#
# Install ePubCheck into a directory.
#
# Usage: bin/installer.sh <version> [dest]
#   dest defaults to ./jar (used by local development without Docker).

set -euo pipefail

VERSION="$1"
DEST="${2:-jar}"

echo "Installing epubcheck ${VERSION} into ${DEST}..."

mkdir -p "${DEST}"
cd "${DEST}"

if [ -e "epubcheck-${VERSION}" ]; then
    echo 'Version already exists.'
    exit 0
fi

curl -fsSL -o "epubcheck-${VERSION}.zip" "https://github.com/w3c/epubcheck/releases/download/v${VERSION}/epubcheck-${VERSION}.zip"
unzip -q "epubcheck-${VERSION}.zip"
rm "epubcheck-${VERSION}.zip"
