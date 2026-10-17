#!/usr/bin/env bash
# B1 staging: CODE ONLY. No database, webserver, service restart or external sends.
# Run on the VPS from a clean checkout of feat/saas-b1-foundation.
set -euo pipefail
if [ "$#" -ne 2 ]; then
  echo "Uso: $0 /CAMINHO/PRODUCAO /CAMINHO/HOMOLOGACAO" >&2
  exit 2
fi
command -v realpath >/dev/null
command -v git >/dev/null
command -v tar >/dev/null
command -v php >/dev/null
PRODUCTION="$(realpath -e -- "$1")"
STAGING="$(realpath -e -- "$2")"
case "$STAGING" in
  /|"$PRODUCTION"|"$PRODUCTION"/*) echo "ERRO: homologação não pode estar na produção ou dentro dela." >&2; exit 2;;
esac
case "$PRODUCTION" in
  "$STAGING"/*) echo "ERRO: produção não pode estar dentro do diretório de homologação." >&2; exit 2;;
esac
if [ -L "$STAGING" ] || [ ! -f "$STAGING/.taskesc-saas-staging-allow" ]; then
  echo "ERRO: crie um diretório de homologação real e o arquivo .taskesc-saas-staging-allow ANTES." >&2
  exit 2
fi
if [ ! -d "$PRODUCTION/.git" ] || [ ! -f "$PRODUCTION/config/config.php" ]; then
  echo "ERRO: caminho de produção não verificado; nenhum arquivo será copiado." >&2
  exit 2
fi
ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"
if [ "$(git branch --show-current)" != "feat/saas-b1-foundation" ]; then
  echo "ERRO: clone da branch feat/saas-b1-foundation obrigatório." >&2
  exit 2
fi
SHA="$(git rev-parse HEAD)"
case "$SHA" in
  *[!0-9a-f]*|'') echo "ERRO: SHA inválido." >&2; exit 2;;
esac
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "ERRO: checkout com mudanças locais; recusei publicar versão diferente do commit." >&2
  exit 2
fi
mkdir -p "$STAGING/releases"
RELEASE="$STAGING/releases/$SHA"
if [ -e "$RELEASE" ]; then
  echo "Código de homologação já preparado: $RELEASE"
else
  TEMP="$(mktemp -d "$STAGING/releases/.incoming-XXXXXXXX")"
  trap 'rm -rf -- "$TEMP"' EXIT
  git archive --format=tar "$SHA" | tar -xf - -C "$TEMP" \
    --exclude='config/config.php' --exclude='.env' --exclude='storage/*'
  test -f "$TEMP/public/api.php" || { echo "ERRO: pacote incompleto." >&2; exit 1; }
  php -l "$TEMP/public/api.php" >/dev/null
  php -l "$TEMP/app/SaasInternalInbox.php" >/dev/null
  php -l "$TEMP/app/SaasInternalInboxMigration.php" >/dev/null
  mv -- "$TEMP" "$RELEASE"
  trap - EXIT
fi
# Never replace an actual staging directory or unknown symlink target.
if [ -e "$STAGING/current" ] && [ ! -L "$STAGING/current" ]; then
  echo "ERRO: current da homologação não é symlink. Nenhuma substituição realizada." >&2
  exit 2
fi
ln -sfn -- "$RELEASE" "$STAGING/.current-next"
mv -Tf -- "$STAGING/.current-next" "$STAGING/current"
printf 'CODE_ONLY_STAGING_OK sha=%s staging=%s\n' "$SHA" "$STAGING/current"
echo "SEM banco/config, sem vhost, sem cron, sem migrations, sem restart e sem mudanças em produção."
