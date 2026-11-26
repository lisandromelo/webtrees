# webtrees — ambiente local

Notas para quem for alterar este checkout. A versão de trabalho é a de desenvolvimento, não um release estável.

## Versão-base

- webtrees **2.3.0-dev** (`app/Webtrees.php`, `VERSION`).
- Branch de trabalho: **main**.
- Esquema atual: `SCHEMA_VERSION = 46` em `app/Webtrees.php`. Migrations em `app/Schema/`.
- Não trocar o checkout para uma tag estável nem montar outra instalação a partir de outra versão.

## Repositórios

| Remote | URL | Uso |
|---|---|---|
| `origin` | `https://github.com/lisandromelo/webtrees.git` | fetch e push |
| `upstream` | `https://github.com/fisharebest/webtrees.git` | somente fetch |

O push de `upstream` está fixado em `DISABLE` (`remote.upstream.pushurl`). `git fetch upstream` continua válido. `git push upstream` falha nesta máquina, antes de autenticar. Não reverter essa URL. Não há remote upstream na configuração global do Git.

## PHP

- O webtrees roda no PHP da imagem `php:8.3-fpm-bookworm`. Não usar o `php` nem o `php8.3` do host para este projeto.
- O `php8.3` do host permanece em 8.3.11 (pacotes `deb.sury.org`). O comando `php` sem versão continua sendo o PHP 7.1.
- Não alterar `update-alternatives`, `/usr/bin/php`, `/etc/alternatives/php` nem o módulo PHP do Apache (`php7_module`).
- Não editar `php.ini` global de nenhuma versão.
- Os pacotes PHP 8.3 do Ubuntu 24.04 são 8.3.6 e exigem `php8.3-common` exatamente nessa versão. Não instalá-los por cima do 8.3.11 da Sury: o apt não consegue conciliar as duas séries.
- O arquivo `/etc/apt/sources.list.d/ondrej-ubuntu-php-jammy.sources` existe com `Enabled: no`. Reativá-lo é mudança global de apt. O PPA hoje publica PHP 8.3.37; instalar extensões por esse caminho sobe o PHP 8.3 inteiro da máquina, porque as extensões dependem do mesmo `php8.3-common`. Isso só pode ser feito depois de avaliação explícita.

O PHP do host permanece como está. As extensões que faltam no `php8.3` do Ubuntu não serão instaladas por apt: elas entram só na imagem `php:8.3-fpm-bookworm`, em `docker/Dockerfile` (`gd`, `intl`, `zip`, `pdo_mysql`, `exif`). A imagem oficial já traz `curl`, `mbstring`, `xml`, `dom`, `simplexml`, `xmlreader`, `xmlwriter`, `sqlite3` e `pdo_sqlite`.

## Composer

- Não montar nem invocar `/usr/bin/composer` do Ubuntu. O shebang desse binário usa o `php` 7.1 do sistema.
- A imagem do projeto copia o Composer da imagem oficial `composer:2` para `/usr/bin/composer`.
- Executar com o PHP 8.3 do container: `php /usr/bin/composer`.
- Instalar dependências com `install`, que respeita o `composer.lock`. Não rodar `composer update` nem editar versões no `composer.json`.
- `composer.json` não define `post-install-cmd`. Os scripts nomeados `ci`, `build` e `png` só rodam quando chamados. Não executar `build`: ele apaga e recria um diretório `webtrees/` e gera um zip. Não executar `png`.
- O plugin `phpstan/extension-installer` está em `allow-plugins` e apenas registra extensões do PHPStan dentro de `vendor/`.
- `composer install` só quando for pedido. Ele grava `vendor/` no checkout, como UID 1000.

## Frontend

- Node 22 e npm já vêm do nvm. Os CSS e JS compilados já estão em `public/`.
- Não rodar `npm install`, `npm ci` nem `npm run build` enquanto `resources/css` e `resources/js` não forem alterados.

## Banco de dados e produção

- Banco do webtrees: MySQL 8.4 no serviço `db` do Compose, volume `webtrees-dbdata`, sem porta publicada no host.
- No assistente, o host do banco é `db`. Nome e usuário saem de `docker/.env` (modelo em `docker/.env.example`). A senha fica só em `docker/.env`, que não entra no Git.
- O MySQL 8.0.46 do Ubuntu permanece em `127.0.0.1:3306` e não é o banco deste projeto.
- Não criar banco, usuário ou tabela sem pedido explícito. O primeiro `docker compose up` do serviço `db` cria o banco vazio dentro do volume novo; isso também espera pedido.
- Não executar migrations. O assistente do webtrees, quando for autorizado, grava `data/config.ini.php` e aplica o esquema. `data/.gitignore` já deixa esse arquivo de fora do Git.
- Não alterar dados nem configuração dos outros MySQL desta máquina, inclusive os containers nas portas 3307 e 3308.
- Não configurar domínio público e não publicar nada na Hostinger.
- Antes de qualquer alteração futura de esquema, ler `app/Schema/` e o `SCHEMA_VERSION`. Avaliar o impacto no banco existente e descrever a migration antes de executá-la.

## Atualizar o código oficial

1. `git fetch upstream`
2. Revisar o que entrou em `upstream/main` antes de integrar.
3. Integrar em branch própria ou em `main` local somente quando isso for pedido.
4. Enviar o resultado para `origin`, nunca para `upstream`.

Não usar `git reset`, `git clean` nem outros comandos destrutivos. Não fazer commit nem push sem pedido explícito.

## Servidor local

O ambiente de execução local planejado é Docker, descrito em `docker/`. Não usar o servidor embutido do PHP no host.

O Apache da porta 80 permanece com PHP 7.1. Não criar vhost, não instalar `libapache2-mod-php8.3` e não desativar o PHP 7.1. Não editar a configuração do daemon Docker.

| Serviço | Imagem | Exposição |
|---|---|---|
| `web` | `nginx:1.27-alpine` | somente `127.0.0.1:8093` |
| `app` | `php:8.3-fpm-bookworm` mais as extensões do Dockerfile | rede `webtrees-internal`, porta 9000 sem publicação no host |
| `db` | `mysql:8.4` | mesma rede, sem porta no host, volume `webtrees-dbdata` |

Os três serviços usam `restart: unless-stopped`. Depois de um reinício do Docker ou da máquina, eles voltam sozinhos. Um `docker compose stop` continua desligando o ambiente até o próximo `up`.

O PHP do container roda como UID/GID 1000:1000, o mesmo dono do checkout. `data/` está em modo 775 e o PHP consegue criar `config.ini.php`, `cache/` e mídia sem `chmod` no restante da árvore. O Nginx só lê o código (`public/` é 664, legível). Não altere permissões do checkout para “corrigir” o container.

O documento do site é a raiz do projeto. Só `index.php` é executado. `/public/` continua acessível. Ficam bloqueados `data/`, `app/`, `vendor/`, `tests/`, `resources/`, `docker/`, `modules_v4/`, `node_modules/`, arquivos ocultos e os manifestos do Composer e do npm. Assets de módulo passam pelo front controller, não por URL direta.

A porta 8080 citada em `CONTRIBUTING.md` está ocupada por outro container. O endereço local previsto é `http://127.0.0.1:8093`.

Build, `composer install`, subida dos containers, criação do banco e migrations continuam dependentes de um pedido explícito. Quando isso for autorizado, a partir de `docker/`:

```bash
docker compose build
docker compose run --rm app php /usr/bin/composer install --no-interaction --no-progress
docker compose up -d
```
