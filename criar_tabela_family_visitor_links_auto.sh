#!/usr/bin/env bash
set -euo pipefail

find_config() {
  local dir="$PWD"
  while [[ "$dir" != "/" ]]; do
    if [[ -f "$dir/data/config.ini.php" ]]; then
      printf '%s\n' "$dir/data/config.ini.php"
      return 0
    fi
    dir="$(dirname "$dir")"
  done
  return 1
}

CONFIG_FILE="$(find_config)" || {
  echo "Não encontrei data/config.ini.php a partir da pasta atual."
  read -r -p "Informe o caminho completo do config.ini.php: " CONFIG_FILE
  [[ -f "$CONFIG_FILE" ]] || { echo "Arquivo não encontrado."; exit 1; }
}

read_config_value() {
  local key="$1" line value
  line="$(grep -E "^[[:space:]]*${key}[[:space:]]*=" "$CONFIG_FILE" | tail -n 1 || true)"
  [[ -n "$line" ]] || return 1
  value="${line#*=}"
  value="${value#\"}"
  value="${value%\"}"
  value="${value#\'}"
  value="${value%\'}"
  printf '%s' "$value"
}

DB_TYPE="$(read_config_value dbtype || true)"
DB_HOST="$(read_config_value dbhost || true)"
DB_PORT="$(read_config_value dbport || true)"
DB_USER="$(read_config_value dbuser || true)"
DB_PASS="$(read_config_value dbpass || true)"
DB_NAME="$(read_config_value dbname || true)"
DB_PREFIX="$(read_config_value tblpfx || true)"

if [[ -z "$DB_NAME" || -z "$DB_USER" ]]; then
  echo "Não consegui ler dbname/dbuser do arquivo: $CONFIG_FILE"
  exit 1
fi
if [[ -n "$DB_TYPE" && "$DB_TYPE" != "mysql" ]]; then
  echo "Tipo de banco não suportado: $DB_TYPE"
  exit 1
fi

TABLE="${DB_PREFIX}family_visitor_links"
if [[ ! "$TABLE" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "Prefixo de tabela inválido no config.ini.php."
  exit 1
fi

MYSQL_BIN="$(command -v mysql || true)"
if [[ -z "$MYSQL_BIN" ]]; then
  echo "Cliente mysql não encontrado. Instale com: sudo apt install mysql-client"
  exit 1
fi

MYSQL_CNF="$(mktemp)"
chmod 600 "$MYSQL_CNF"
trap 'rm -f "$MYSQL_CNF"' EXIT
{
  printf '[client]\nuser=%s\npassword="%s"\nhost=%s\n' "$DB_USER" "${DB_PASS//\"/\\\"}" "${DB_HOST:-localhost}"
  [[ -z "$DB_PORT" ]] || printf 'port=%s\n' "$DB_PORT"
} > "$MYSQL_CNF"

echo "Configuração encontrada: $CONFIG_FILE"
echo "Banco: $DB_NAME"
echo "Tabela: $TABLE"
echo

SQL="CREATE TABLE IF NOT EXISTS \`${TABLE}\` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tree_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    label VARCHAR(120) NOT NULL DEFAULT '',
    INDEX visitor_tree_idx (tree_id)
) ENGINE=InnoDB;"

echo "Criando/verificando tabela..."
"$MYSQL_BIN" --defaults-extra-file="$MYSQL_CNF" --database="$DB_NAME" --execute="$SQL"
echo
echo "Verificando estrutura:"
"$MYSQL_BIN" --defaults-extra-file="$MYSQL_CNF" --database="$DB_NAME" --execute="DESCRIBE \`${TABLE}\`;"
echo
echo "Concluído. A tabela está pronta (ou já existia)."
