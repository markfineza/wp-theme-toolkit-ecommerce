#!/bin/sh
# Builds installable ZIPs into dist/: nexora-commerce.zip and nexora-commerce-toolkit.zip
set -e
cd "$(dirname "$0")/.."
mkdir -p dist
rm -f dist/nexora-commerce.zip dist/nexora-commerce-toolkit.zip
find nexora-commerce nexora-commerce-toolkit -name '.DS_Store' -delete
for php in $(find nexora-commerce nexora-commerce-toolkit -name '*.php'); do php -l "$php" >/dev/null || { echo "Lint failed: $php"; exit 1; }; done
zip -rq dist/nexora-commerce.zip nexora-commerce -x '*/.*'
zip -rq dist/nexora-commerce-toolkit.zip nexora-commerce-toolkit -x '*/.*'
ls -lh dist/
