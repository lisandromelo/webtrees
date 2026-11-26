#!/usr/bin/env bash
set -euo pipefail

echo "Criar tabela family_visitor_links do Webtrees"
echo "------------------------------------------"

read -r -p "Host MySQL [localhost]: " DB_HOST
DB_HOST="${DB_HOST:-localhost}"

read -r -p "Nome do banco: " DB_NAME
if [[ -z "$DB_NAME" ]]; then
  echo "Erro: informe o nome do banco."
  exit 1
fi

read -r -p "Usuário MySQL: " DB_USER
if [[ -z "$DB_USER" ]]; then
  echo "Erro: informe o usuário."
  exit 1
fi

read -r -s -p "Senha MySQL: " DB_PASS
echo
read -r -p "Prefixo das tabelas (deixe vazio se não houver): " DB_PREFIX

TABLE="${DB_PREFIX}family_visitor_links"
if [[ ! "$TABLE" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "Erro: prefixo inválido. Use somente letras, números e underscore."
  exit 1
fi

SQL=$(cat <<SQL
CREATE TABLE IF NOT EXISTS \`${TABLE}\` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tree_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    label VARCHAR(120) NOT NULL DEFAULT '',
    INDEX visitor_tree_idx (tree_id)
) ENGINE=InnoDB;
SQL
)

if ! command -v mysql >/dev/null 2>&1; then
  echo "Erro: cliente mysql não encontrado. Instale o cliente MySQL/MariaDB."
  exit 1
fi

echo
echo "Conectando e criando/verificando a tabela: ${TABLE}"
MYSQL_PWD="$DB_PASS" mysql --host="$DB_HOST" --user="$DB_USER" --database="$DB_NAME" --batch --execute="$SQL"

echo
echo "Estrutura da tabela:"
MYSQL_PWD="$DB_PASS" mysql --host="$DB_HOST" --user="$DB_USER" --database="$DB_NAME" --execute="DESCRIBE \`${TABLE}\`;"
unset DB_PASS
echo
echo "Concluído."
