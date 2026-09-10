# Banco de dados

Para migrar do XAMPP preservando histórico, siga [Implantação MariaDB](../../docs/implantacao-mariadb.md). O guia inclui backup lógico com `--result-file` (compatível com PowerShell), restauração em destino vazio e contas distintas para aplicação e migrations. Criar um banco vazio e reimportar o CSV não recupera automaticamente o histórico antigo.

A implantação recomendada cria um banco MariaDB vazio e executa:

```bat
bin\cake migrations migrate
```

Depois, importe o CSV atual com `bin\cake import_pending_reports` ou `bin\cake import_report CAMINHO.csv`.

Não foi incluído dump com dados nesta entrega porque os snapshots contêm payload bruto e identificadores de usuários do TOTVS. Caso o TI aprove formalmente a transferência desses dados, gere o backup no ambiente autorizado:

```bat
mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 -h HOST -P 3306 -u USUARIO -p pcm > deployment\database\pcm_backup.sql
```

O comando solicita a senha interativamente; não inclua senha na linha de comando ou no arquivo.
