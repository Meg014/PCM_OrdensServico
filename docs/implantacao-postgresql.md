# Implantação do datasource local em PostgreSQL 16

O datasource `default` aceita PostgreSQL ou MariaDB/MySQL por configuração. Em produção use
`DB_DRIVER=postgres`; em desenvolvimento, `DB_DRIVER=mysql` mantém o comportamento anterior.
A conexão `protheus` permanece isolada, usa SQL Server e é somente leitura.

## Pré-requisitos no Ubuntu 24.04

O PHP-FPM e o PHP usado pela CLI precisam carregar a mesma extensão PostgreSQL:

```bash
sudo apt install php8.3-pgsql
php -m | grep -E 'PDO|pdo_pgsql'
php-fpm8.3 -i | grep -i pdo_pgsql
```

O Composer 2.7.1 informado para o servidor é afetado por CVE-2026-84361. Atualize-o para, no mínimo,
2.10.3 antes de instalar dependências:

```bash
sudo composer self-update 2.10.3
composer --version
```

O arquivo `config/.env` deve ser legível somente pela conta que executa a aplicação. Os valores de
host, usuário e senha devem ser fornecidos pela TI; não há credencial padrão de produção:

```dotenv
DB_DRIVER=postgres
DB_HOST=<fornecido-pela-ti>
DB_PORT=5432
DB_DATABASE=pcm_ordem
DB_USERNAME=<fornecido-pela-ti>
DB_PASSWORD=<fornecido-pela-ti>
DB_SCHEMA=public
DB_ENCODING=utf8
```

## Comandos após o deploy

Execute na raiz da versão implantada, usando a mesma conta do PHP-FPM:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
php bin/cake.php migrations status
php bin/cake.php migrations migrate
php bin/cake.php cache clear_all
php bin/cake.php pcm_health
php bin/cake.php protheus_health
```

Não execute migrations contra a conexão `protheus`. Antes de liberar o tráfego, confirme que
`migrations status` não mostra migrations pendentes e que os dois health checks terminam com sucesso.

## Histórico existente

As migrations criam todas as estruturas históricas, inclusive importações e snapshots, mas não
transportam registros de uma instalação MariaDB existente. Se houver histórico a preservar, a TI deve
planejar uma migração lógica MariaDB → PostgreSQL, validar contagens, hashes, chaves e sequências em um
ambiente de homologação e manter o banco de origem intacto até a aceitação. Reimportar somente a
carteira atual não substitui essa migração histórica.
