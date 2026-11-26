#!/usr/bin/env bash
# Atualiza o webtrees de familia.lisandromelo.com.br a partir de um commit.
# A pasta remota é a raiz inteira do webtrees. data/ e vendor/ permanecem no servidor.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REMOTE="${WT_SSH:-u857442812@212.85.9.177}"
PORT="${WT_SSH_PORT:-65002}"
REMOTE_DIR="${WT_REMOTE_DIR:-/home/u857442812/domains/lisandromelo.com.br/public_html/familia}"
REF="HEAD"
LIVE=0

usage() {
  echo "Uso: $0 [--dry-run|--live] [--ref COMMIT]"
  echo "O padrão é simular. --live envia os arquivos do commit e garante a tabela dos links temporários."
  echo "Arquivos não commitados não entram. data/, vendor/ e a mídia do servidor não são apagados."
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --live) LIVE=1 ;;
    --dry-run) LIVE=0 ;;
    --ref)
      REF="${2:?informe o commit}"
      shift
      ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Opção desconhecida: $1" >&2; usage >&2; exit 1 ;;
  esac
  shift
done

git -C "$ROOT_DIR" rev-parse --verify "${REF}^{commit}" >/dev/null
COMMIT="$(git -C "$ROOT_DIR" rev-parse --short "${REF}^{commit}")"

if [[ -n "$(git -C "$ROOT_DIR" status --porcelain)" ]]; then
  echo "Aviso: há alterações locais. Esta atualização usa o commit ${COMMIT}, sem arquivos não commitados."
fi

TMP="$(mktemp -d)"
cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT
mkdir -p "$TMP/src"
# checkout-index exporta o commit inteiro. git archive obedeceria ao export-ignore
# e deixaria de fora composer.lock, testes e outros arquivos que já estão no ar.
GIT_INDEX_FILE="$TMP/index" git -C "$ROOT_DIR" read-tree "$REF"
GIT_INDEX_FILE="$TMP/index" git -C "$ROOT_DIR" checkout-index -a -f --prefix="$TMP/src/"

for path in index.php app public composer.lock; do
  [[ -e "$TMP/src/$path" ]] || { echo "Commit sem $path; atualização interrompida." >&2; exit 1; }
done
grep -q "vendor/autoload.php" "$TMP/src/index.php" || {
  echo "index.php do commit não é a raiz do webtrees; atualização interrompida." >&2
  exit 1
}

SSH=(ssh -p "$PORT" -o BatchMode=yes -o StrictHostKeyChecking=accept-new)
RSYNC_SSH="ssh -p $PORT -o BatchMode=yes -o StrictHostKeyChecking=accept-new"

echo "Commit: ${COMMIT}"
echo "Destino: ${REMOTE}:${REMOTE_DIR}"
echo "Modo: $([[ $LIVE -eq 1 ]] && echo aplicar || echo simulação)"

mapfile -t BEFORE < <("${SSH[@]}" "$REMOTE" "cd '$REMOTE_DIR' && stat -c '%n %s' data/config.ini.php && readlink data/media || true")

rsync_common=(
  -rlptz
  --checksum
  --delete
  --itemize-changes
  --exclude=/data/
  --exclude=/vendor/
  --exclude=/docker/
  --exclude=/scripts/
  --exclude=/node_modules/
  --exclude=/AGENTS.md
  --exclude=/deploy.sh
  --exclude=/deploy-config
  --exclude=/deploy-config.example
  --exclude=/DEPLOY-HOSTINGER.md
  --exclude=/.git/
  --exclude=/default.php
  --exclude=/resources/lang/*/messages.php
  -e "$RSYNC_SSH"
)

show_changes() {
  grep -E '^(\*|[<c])' "$1" || true
}

if [[ $LIVE -eq 0 ]]; then
  rsync -n "${rsync_common[@]}" "$TMP/src/" "$REMOTE:$REMOTE_DIR/" >"$TMP/rsync.txt"
  show_changes "$TMP/rsync.txt"
  echo
  echo "Simulação apenas. Nada foi alterado no servidor."
  echo "Para aplicar: $0 --live"
  exit 0
fi

rsync "${rsync_common[@]}" "$TMP/src/" "$REMOTE:$REMOTE_DIR/" >"$TMP/rsync.txt"
show_changes "$TMP/rsync.txt"

LOCAL_LOCK="$(sha256sum "$TMP/src/composer.lock" | awk '{print $1}')"
REMOTE_LOCK="$("${SSH[@]}" "$REMOTE" "sha256sum '$REMOTE_DIR/composer.lock'" | awk '{print $1}')"
if [[ "$LOCAL_LOCK" != "$REMOTE_LOCK" ]]; then
  echo "composer.lock mudou. Instalando dependências no servidor."
  "${SSH[@]}" "$REMOTE" "cd '$REMOTE_DIR' && composer install --no-dev --no-interaction --no-progress"
else
  echo "composer.lock igual. vendor/ do servidor foi mantido."
fi

echo "Garantindo a tabela dos links temporários."
"${SSH[@]}" "$REMOTE" "php" <<'PHP'
<?php

declare(strict_types=1);

$root = '/home/u857442812/domains/lisandromelo.com.br/public_html/familia';
$config = parse_ini_file($root . '/data/config.ini.php');
if (!is_array($config)) {
    fwrite(STDERR, "config\n");
    exit(1);
}

$prefix = (string) ($config['tblpfx'] ?? '');
if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
    fwrite(STDERR, "prefix\n");
    exit(1);
}

$port = (string) ($config['dbport'] ?? '');
if ($port === '') {
    $port = '3306';
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['dbhost'], $port, $config['dbname']),
    (string) $config['dbuser'],
    (string) $config['dbpass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$table = '`' . $prefix . 'family_visitor_links`';
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tree_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NULL,
        revoked_at DATETIME NULL,
        label VARCHAR(120) NOT NULL DEFAULT '',
        INDEX visitor_tree_idx (tree_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$columns = $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN);
$expected = ['id', 'tree_id', 'token_hash', 'created_at', 'expires_at', 'revoked_at', 'label'];
$actual = $columns;
sort($actual);
sort($expected);
if ($actual !== $expected) {
    fwrite(STDERR, 'columns ' . implode(',', $columns) . "\n");
    exit(1);
}

echo "table-ok\n";
PHP

mapfile -t AFTER < <("${SSH[@]}" "$REMOTE" "cd '$REMOTE_DIR' && stat -c '%n %s' data/config.ini.php && readlink data/media || true")
if [[ "${BEFORE[*]}" != "${AFTER[*]}" ]]; then
  echo "A configuração ou o atalho de mídia mudou durante a atualização." >&2
  printf 'antes: %s\n' "${BEFORE[@]}" >&2
  printf 'depois: %s\n' "${AFTER[@]}" >&2
  exit 1
fi

echo "Configuração e mídia preservadas."
CODE="$(curl -fsS -o /tmp/wt-familia-home.html -w '%{http_code}' https://familia.lisandromelo.com.br/ || true)"
echo "Site: HTTP ${CODE}"
if [[ "$CODE" != "200" && "$CODE" != "302" ]]; then
  echo "A página inicial não respondeu como esperado." >&2
  exit 1
fi
echo "Atualização aplicada a partir do commit ${COMMIT}."
