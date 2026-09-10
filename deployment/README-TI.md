# PCM — Guia de implantação para TI

Para instalar o serviço MariaDB externo e migrar do XAMPP, siga o [guia detalhado com comandos e checklist](../docs/implantacao-mariadb.md). Ele define a configuração compartilhada, o backup e as contas de aplicação/migrations. Não use o banco do XAMPP em produção.

## 1. Finalidade e arquitetura

Aplicação interna CakePHP para importar a carteira completa de Ordens de Serviço do TOTVS e apresentar dashboards PCM. O CSV mais recente é uma fotografia completa: indicadores atuais consultam somente a importação bem-sucedida de maior `report_date`; snapshots anteriores ficam exclusivamente para auditoria e comparação por data.

Fluxo: compartilhamento de rede → comando CakePHP → validação CSV/XLSX → MariaDB → dashboards web.

O sistema não possui autenticação de usuário e deve ser publicado SOMENTE na rede interna da empresa ou atrás dos controles de acesso corporativos. Não publicar diretamente na internet.

## 2. Requisitos

- Windows Server 2019/2022 ou equivalente homologado pelo TI.
- IIS com URL Rewrite ou Apache 2.4; DocumentRoot obrigatório em `webroot/`.
- PHP 8.2 ou 8.3, 64 bits, com `intl`, `mbstring`, `pdo_mysql`, `mysqli`, `openssl`, `fileinfo`, `iconv`, `xml`, `simplexml`, `dom`, `zip` e `gd`.
- Composer 2.x.
- MariaDB externo em versão mantida e homologada pela TI, charset `utf8mb4`.
- CakePHP 5.4 e dependências fixadas em `composer.lock`.

Verifique com `php -m`, `php -v` e `composer --version`.

## 3. Instalação

1. Copiar o projeto para um diretório como `C:\inetpub\pcm`.
2. Executar na raiz:

```bat
composer install --no-dev --optimize-autoloader
```

3. Configurar todos os `DB_*` em `config/.env`, compartilhado por CLI, web e agendador. Use [config/env.example](config/env.example) como referência; preserve os valores locais existentes. O arquivo prevalece sobre variáveis dos processos e não deve ser versionado. `.env` na raiz não é carregado.
4. Gerar `SECURITY_SALT` forte e único. Não versionar a senha ou o salt real.
5. Garantir `DEBUG=false` e `APP_FULL_BASE_URL` configurado.
6. Definir `PCM_REPORT_PATH` com o UNC real autorizado pela TI; o exemplo usa apenas `\\servidor\caminho\relatorios`.

`PCM_REPORT_PATH` tem prioridade sobre o nome legado `PCM_REPORTS_INCOMING`.

## 4. Permissões

A identidade do pool IIS e a conta do Agendador precisam escrever em `logs`, `tmp`, `PCM_REPORTS_PROCESSED` e `PCM_REPORTS_ERROR`.

A conta que executa a tarefa precisa de leitura no compartilhamento UNC. Não use `SYSTEM`: normalmente essa conta não possui credenciais de rede. Recomenda-se conta técnica de domínio, por exemplo `DOMINIO\svc_pcm`, com logon em lote e permissão mínima de leitura na pasta da rede.

## 5. MariaDB e migrations

Crie `pcm` com `utf8mb4_unicode_ci`. Siga o [SQL do guia MariaDB](../docs/implantacao-mariadb.md#3-criar-banco-e-usuário) para criar `pcm_app` e `pcm_migrator` com senhas próprias, restritos ao IP de origem do PHP. Com site e agendador parados, configure temporariamente as credenciais de migrations no datasource `default` e depois restaure `pcm_app`.

Depois:

```bat
php bin/cake.php migrations migrate --connection default
php bin/cake.php migrations status --connection default
```

Para backup opcional, consulte [database/README.md](database/README.md). Não copie pastas físicas do MariaDB/XAMPP.

## 6. Importação inicial e operação

Importação explícita para validação inicial:

```bat
bin\cake import_report "\\servidor\compartilhamento\Relatorio_OS_2026-08-28.csv"
```

Operação automática:

```bat
bin\cake import_pending_reports
```

O comando procura CSV/XLSX, espera o arquivo estabilizar, calcula SHA-256, ignora conteúdo já importado, valida 57 cabeçalhos e importa em transação. Arquivo inválido não substitui a última carteira válida. Nomes datados fornecem o `report_date`; um arquivo fixo configurado em `PCM_OPERATIONAL_REPORT_FILE` usa data operacional documentada e hash.

`PCM_REPORT_PATH` é uma fonte corporativa somente leitura: o importador nunca cria ou remove essa pasta e nunca move, renomeia ou exclui seus arquivos. O processamento ocorre sobre uma cópia em `PCM_REPORTS_STAGING`, depois arquivada localmente em `PCM_REPORTS_PROCESSED` ou `PCM_REPORTS_ERROR`.

## 7. Agendador de Tarefas do Windows

Crie uma tarefa executada a cada cinco minutos:

- Programa: `C:\php\php.exe`
- Argumentos: `bin\cake.php import_pending_reports`
- Iniciar em: `C:\inetpub\pcm`
- Usuário: conta técnica de domínio com acesso ao UNC e às pastas locais.
- Executar independentemente de o usuário estar conectado.
- Não iniciar nova instância se a anterior ainda estiver executando.

Use [scripts/importar-relatorios.cmd](scripts/importar-relatorios.cmd) ou revise o exemplo [scripts/registrar-tarefa-exemplo.ps1](scripts/registrar-tarefa-exemplo.ps1). Não grave senha no script. Os scripts recebem a raiz do projeto e o executável PHP como argumentos; o exemplo PowerShell também exige `-TaskUser`. Consulte os exemplos de uso no guia MariaDB.

## 8. Servidor web

O site deve apontar apenas para `C:\inetpub\pcm\webroot`. Nunca publique a raiz do projeto. Configure `APP_FULL_BASE_URL`, HTTPS interno quando disponível e bloqueio de acesso externo no firewall/proxy corporativo.

Em IIS, habilite FastCGI/PHP e URL Rewrite para respeitar `webroot/.htaccess` por configuração equivalente. Em Apache, habilite `mod_rewrite` e `AllowOverride All` somente no DocumentRoot.

## 9. Logs e diagnóstico

- Aplicação: `logs/error.log` e `logs/debug.log`.
- Agendador sugerido: `logs/agendador-importacao.log`.
- Histórico funcional: página `/importacoes`.
- Diagnóstico CLI seguro:

```bat
bin\cake pcm_health
```

O diagnóstico informa banco, acesso à pasta configurada e última importação sem mostrar credenciais.

## 10. Atualização, rollback e backup

Antes de atualizar: backup do banco, cópia do código e registro da versão. Execute `composer install --no-dev --optimize-autoloader`, migrations e testes de saúde. Para rollback de código, restaure a versão anterior; migrations de regra funcional podem ser irreversíveis, portanto restaure o backup do banco quando necessário. Nunca execute `down` sem revisar cada migration.

Defina retenção de banco, logs e arquivos processados conforme política corporativa.

## 11. Troubleshooting

- `Access denied` no UNC: execute a tarefa com conta de domínio e teste `Test-Path` usando a mesma identidade.
- Banco indisponível: valide firewall, DNS, porta 3306 e variáveis `DB_*`.
- CSV rejeitado: confira Windows-1252/UTF-8, delimitador, 57 cabeçalhos e logs.
- Nenhuma nova importação: compare SHA-256; conteúdo idêntico é ignorado intencionalmente.
- Dashboard antigo: confirme `report_date`, status `success` e execute `bin\cake pcm_health`.
- Erro 500: mantenha `DEBUG=false`, consulte `logs/error.log` e não exponha stack trace ao usuário.

## 12. Critérios de aceite

Concluir [CHECKLIST-IMPLANTACAO.md](CHECKLIST-IMPLANTACAO.md), comparar o total do CSV com o dashboard, testar duplicidade por hash e confirmar que snapshots antigos não entram nos indicadores atuais.
