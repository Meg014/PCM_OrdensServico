# Migração lógica PCM: MariaDB para PostgreSQL

Os comandos transportam somente as nove tabelas funcionais do PCM. `cake_migrations` não faz parte do
arquivo, e o datasource `protheus` nunca é consultado. O arquivo usa JSON Lines UTF-8, formato
`pcm-logical-transfer` versão 1, para processar snapshots grandes sem carregá-los integralmente em memória.

O arquivo contém hashes de senha e tokens de dispositivos. Ele não contém credenciais de banco nem o
`config/.env`, mas deve ser tratado como dado sigiloso, transportado por SSH e removido depois da
homologação.

## Ordem das tabelas

1. `maintenance_areas`
2. `cost_centers`
3. `equipment`
4. `services`
5. `report_imports`
6. `work_orders`
7. `users`
8. `work_order_snapshots`
9. `tv_devices`

A ordem respeita todas as foreign keys. A escrita usa SQL parametrizado diretamente na conexão, sem
entities, callbacks ou setters de senha. IDs, hashes e timestamps não são recalculados.

## A) Exportar no Windows/MariaDB

Escolha uma janela sem gravações no PCM para evitar alterações posteriores ao snapshot exportado. No
PowerShell, na raiz do projeto:

```powershell
New-Item -ItemType Directory -Force -Path 'C:\PCM\transferencia' | Out-Null
php bin\cake.php pcm_data_export 'C:\PCM\transferencia\pcm-transfer-v1.jsonl'
php bin\cake.php pcm_data_verify 'C:\PCM\transferencia\pcm-transfer-v1.jsonl'
Get-FileHash -Algorithm SHA256 'C:\PCM\transferencia\pcm-transfer-v1.jsonl'
```

O primeiro comando recusa substituir um arquivo existente. `--overwrite` deve ser usado somente depois
de conferir o caminho; durante a substituição, o arquivo anterior é protegido até o novo estar completo.
O `pcm_data_verify` feito na origem comprova que o arquivo representa o banco exportado.

## B) Copiar para Ubuntu

Substitua usuário e host pelos valores fornecidos pela TI:

```powershell
scp 'C:\PCM\transferencia\pcm-transfer-v1.jsonl' '<usuario>@<servidor>:/tmp/pcm-transfer-v1.jsonl'
```

No Ubuntu:

```bash
sha256sum /tmp/pcm-transfer-v1.jsonl
sudo install -o <usuario-php> -g <grupo-php> -m 600 \
  /tmp/pcm-transfer-v1.jsonl /caminho/seguro/pcm-transfer-v1.jsonl
```

Compare o SHA-256 exibido no Windows com o exibido no Ubuntu antes de importar.

## C) Importar no PostgreSQL

As 10 migrations devem estar aplicadas e todas as nove tabelas alvo devem estar vazias. Execute usando
a mesma conta e o mesmo `config/.env` do PHP-FPM:

```bash
cd /caminho/do/pcm
php bin/cake.php migrations status
php bin/cake.php pcm_data_import /caminho/seguro/pcm-transfer-v1.jsonl
```

O comando não oferece merge nem limpeza automática. Se qualquer tabela contiver uma linha, a importação
é recusada. Toda a carga, validação de relacionamentos e sincronização das sequences PostgreSQL ocorre em
uma única transação; qualquer falha produz rollback.

## D) Verificar a migração

```bash
cd /caminho/do/pcm
php bin/cake.php pcm_data_verify /caminho/seguro/pcm-transfer-v1.jsonl
php bin/cake.php pcm_health
php bin/cake.php protheus_health
```

A verificação compara, por tabela, contagem, SHA-256 da sequência ordenada de IDs e SHA-256 do conteúdo
normalizado. Também procura foreign keys órfãs. Só libere a aplicação se todas as tabelas mostrarem
`IDs=OK`, `dados=OK`, zero órfãos e o comando terminar com exit code 0.

Depois da aceitação, remova as cópias do arquivo conforme a política de descarte seguro da empresa. Não
apague nem altere o MariaDB de origem até a homologação funcional e a autorização da TI.
