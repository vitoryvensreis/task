#!/usr/bin/env bash
set -euo pipefail

VERSION='5.7.4'
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="$ROOT/public/assets/vendor"
JS="$DEST/centrifuge-$VERSION.js"
LICENSE="$DEST/centrifuge-js.LICENSE"
CHECKSUM="$DEST/centrifuge-$VERSION.sha256"
mkdir -p "$DEST"

if [ -f "$JS" ] && [ -f "$LICENSE" ] && [ -f "$CHECKSUM" ]; then
  (cd "$DEST" && sha256sum -c "$(basename "$CHECKSUM")")
  node --check "$JS"
  exit 0
fi

TMP="$(mktemp -d)"
cleanup(){ rm -rf "$TMP"; }
trap cleanup EXIT
cd "$TMP"
PACK="$(npm pack "centrifuge@$VERSION" --silent)"
tar -xzf "$PACK" package/dist/centrifuge.js package/LICENSE package/package.json
node -e "const p=require('$TMP/package/package.json'); if(p.name!=='centrifuge'||p.version!=='$VERSION') process.exit(1)"
install -m 0644 package/dist/centrifuge.js "$JS"
install -m 0644 package/LICENSE "$LICENSE"
(cd "$DEST" && sha256sum "$(basename "$JS")" > "$(basename "$CHECKSUM")")
node --check "$JS"
