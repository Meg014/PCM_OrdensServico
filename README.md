# PCM — Ordens de Serviço TOTVS

A [revisão funcional de setembro de 2026](docs/revisao-funcional-pcm.md) documenta a exclusão de canceladas dos KPIs, as classificações de serviço, os novos cards, filtros, datas e checklist de validação. Ela não exige migration nem reimportação.

## MariaDB externo no Windows

Consulte [Implantação com MariaDB externo](docs/implantacao-mariadb.md) para instalação do serviço, backup, SQL de criação do banco/usuários, configuração e comandos para desenvolvimento e TI.

O datasource `default` está centralizado em `config/app.php`, com driver `Cake\Database\Driver\Mysql` e `utf8mb4`. Configure `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` e `PCM_REPORT_PATH` no arquivo local protegido `config/.env`. Preencha todos os `DB_*` nesse arquivo para que CLI, navegador e agendador usem o mesmo destino; seus valores prevalecem sobre o ambiente dos processos. `.env` na raiz não é carregado, e `DATABASE_URL` não substitui essa configuração.

Use [deployment/config/env.example](deployment/config/env.example) como referência, sem sobrescrever configurações locais existentes. Em produção, também configure `DEBUG=false`, `SECURITY_SALT` e `APP_FULL_BASE_URL`. Não use o banco do XAMPP em produção. SQLite permanece restrito aos testes automatizados.

`php bin/cake.php pcm_health` mostra driver, destino, conectividade, versão do servidor e acesso aos relatórios sem imprimir credenciais. O importador continua usando exclusivamente `default`.

![Build Status](https://github.com/cakephp/app/actions/workflows/ci.yml/badge.svg?branch=5.x)
[![Total Downloads](https://img.shields.io/packagist/dt/cakephp/app.svg?style=flat-square)](https://packagist.org/packages/cakephp/app)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg?style=flat-square)](https://github.com/phpstan/phpstan)

A skeleton for creating applications with [CakePHP](https://cakephp.org) 5.x.

The framework source code can be found here: [cakephp/cakephp](https://github.com/cakephp/cakephp).

## Installation

1. Download [Composer](https://getcomposer.org/doc/00-intro.md) or update `composer self-update`.
2. Run `php composer.phar create-project --prefer-dist cakephp/app [app_name]`.

If Composer is installed globally, run

```bash
composer create-project --prefer-dist cakephp/app
```

In case you want to use a custom app dir name (e.g. `/myapp/`):

```bash
composer create-project --prefer-dist cakephp/app myapp
```

You can now either use your machine's webserver to view the default home page, or start
up the built-in webserver with:

```bash
bin/cake server -p 8765
```

Then visit `http://localhost:8765` to see the welcome page.

## Demo app

Check out the [5.x-demo branch](https://github.com/cakephp/app/tree/5.x-demo), which contains demo migrations and a seeder.
See the [README](https://github.com/cakephp/app/blob/5.x-demo/README.md) on how to get it running.

## Update

Since this skeleton is a starting point for your application and various files
would have been modified as per your needs, there isn't a way to provide
automated upgrades, so you have to do any updates manually.

## Operação e configuração

### Carteira atual e regra de STATUS do PCM (v4)

O relatório diário do TOTVS representa a carteira operacional atual. Cada novo relatório substitui o anterior para fins de indicadores e dashboards. Arquivos antigos podem ser preservados para auditoria, mas não são acumulados na carteira atual.

- Se `Situação` indicar Cancelado/Cancelada: `CANCELADA`.
- Senão, se `Término = Sim`: `FECHADA`.
- Senão: `EM ABERTO`.

Os campos importados permanecem inalterados. `P. In. Man.` e `P. Fim Man.`, com suas horas, representam planejamento/registro. `Real. Início` e `Real. Fim` representam a execução real. `R. In. Man.` e `R. Fim Man.` permanecem disponíveis como dados adicionais do TOTVS. Nenhum campo temporal participa da regra de STATUS. Canceladas ficam fora dos KPIs operacionais, mas permanecem disponíveis para auditoria.

### Fonte operacional CSV ou XLSX

O importador aceita CSV e XLSX com as mesmas 57 colunas posicionais do relatório TOTVS. CSV é a fonte operacional principal; o XLSX permanece suportado para compatibilidade. O leitor CSV detecta UTF-8 ou Windows-1252, identifica o delimitador entre `;`, `,` e tabulação e localiza o cabeçalho mesmo quando existe preâmbulo.

`PCM_REPORT_PATH` é sempre uma fonte oficial somente leitura. Todos os arquivos encontrados nela permanecem intactos; o importador cria uma cópia em staging local e arquiva essa cópia em processados ou erro. O SHA-256 impede reimportação e a última importação bem-sucedida, pela ordem de ID, define a carteira atual.

A data operacional do arquivo fixo vem da data de modificação do arquivo, interpretada em `APP_DISPLAY_TIMEZONE`. Após uma importação bem-sucedida, uma cópia é preservada em `PCM_REPORTS_PROCESSED` com nome no formato `Relatorio_OS_YYYY-MM-DD_HHMMSS.xlsx`. Essa cópia não alimenta diretamente os dashboards.

Configurações relevantes:

- `PCM_REPORT_PATH`: pasta operacional principal, inclusive compartilhamento UNC em produção.
- `PCM_REPORTS_INCOMING`: alias legado/fallback para a pasta de entrada.
- `PCM_REPORTS_PROCESSED`: arquivo de cópias processadas para auditoria.
- `PCM_REPORTS_ERROR`: relatórios rejeitados.
- `PCM_REPORTS_STAGING`: área temporária local controlada pela aplicação.
- `PCM_OPERATIONAL_REPORT_FILE`: nome do arquivo fixo.
- `PCM_REPORT_SHEET`: aba esperada do XLSX.
- `PCM_MINIMUM_FILE_AGE_SECONDS`: espera antes de ler arquivo recém-substituído.
- `PCM_SCHEDULE_INTERVAL_SECONDS`: intervalo recomendado para o agendador externo.
- `APP_DISPLAY_TIMEZONE`: timezone operacional e de apresentação.

Read and edit the environment specific `config/app_local.php` and set up the
`'Datasources'` and any other configuration relevant for your application.
Other environment agnostic settings can be changed in `config/app.php`.

## Layout

The app skeleton uses [Milligram](https://milligram.io/) (v1.3) minimalist CSS
framework by default. You can, however, replace it with any other library or
custom styles.
