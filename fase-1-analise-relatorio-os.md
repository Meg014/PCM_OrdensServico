# Fase 1 — análise do relatório de Ordens de Serviço

> Documento histórico da análise inicial. As regras v2 e os indicadores descritos abaixo estão obsoletos.
> Para operação, prevalecem a regra v4 e o fluxo documentados no `README.md` e em
> `docs/refatoracao-qualidade-codigo.md`.

## Escopo e arquivo analisado

Esta documentação foi produzida pela leitura direta de `relatorios_teste/Relatorio_OS_2026-08-21.xlsx`. Nenhum projeto CakePHP, banco de dados ou tela foi criado.

| Item | Resultado |
|---|---|
| Arquivo | `Relatorio_OS_2026-08-21.xlsx` |
| SHA-256 | `C7D77770B4515C86E99A1B3987DCE21E5F6E0502F47400850B4CAA953FD05FA1` |
| Aba | `sclxd280` (única aba) |
| Registros | 575, além do cabeçalho |
| Colunas | 57 (`A:BE`) |
| Fórmulas | 0 |
| OS sem número | 0 |
| Números de OS distintos | 575 |
| Duplicidades de OS no relatório | 0 |
| Intervalo de `Data Origin.` | 01/05/2026 a 19/08/2026 |
| Data inferida do nome do arquivo | 21/08/2026 |

### Observação de codificação

O próprio XLSX contém o caractere de substituição Unicode `�` em cabeçalhos e textos acentuados, por exemplo `Servi�o`, `�rea Manut.`, `T�rmino` e `Situa��o`. Não se trata apenas de exibição do analisador. A importação deve reconhecer tanto a grafia corrompida encontrada quanto a grafia correta esperada, por meio de aliases de cabeçalho, e registrar um alerta de qualidade. Não se deve alterar silenciosamente os valores de negócio originais; pode-se manter o valor bruto e uma versão normalizada.

## 1 e 2. Inventário completo e tipos aparentes

Os nomes abaixo são apresentados com acentuação restaurada para leitura. Como há cabeçalhos repetidos para data e hora, o importador deve identificar cada campo também pela posição e validar a sequência completa do cabeçalho.

| # | Col. | Cabeçalho | Tipo aparente / domínio | Preenchimento | Distintos | Uso e observações |
|---:|:---:|---|---|---:|---:|---|
| 1 | A | Filial | inteiro no arquivo; armazenar `varchar(10)` | 575/575 | 1 | Chave de origem; valor `1` em todas as linhas. |
| 2 | B | Ordem Serv. | inteiro no arquivo; armazenar `varchar(30)` | 575/575 | 575 | Identificador da OS no TOTVS; faixa observada 4347–4934. |
| 3 | C | Plano Manut. | inteiro/código | 575/575 | 1 | Sempre `0`; sem utilidade analítica neste arquivo. |
| 4 | D | Data Origin. | data | 575/575 | 69 | Provável data de origem/abertura; 01/05 a 19/08/2026. |
| 5 | E | Tipo O. S. | texto/código | 575/575 | 1 | Sempre `Bem`. |
| 6 | F | Bem | código alfanumérico | 575/575 | 347 | Identificador do equipamento. Duas células são numéricas; tratar sempre como texto. |
| 7 | G | Nome do Bem | texto | 575/575 | 342 | Descrição do equipamento. Cada código observado mapeia para um único nome. |
| 8 | H | Serviço | código alfanumérico | 575/575 | 23 | Classificação detalhada de serviço. |
| 9 | I | Nome Serviço | texto | 575/575 | 22 | Descrição do serviço. Cada código observado mapeia para um único nome. |
| 10 | J | Sequência | inteiro/código | 575/575 | 1 | Sempre `0`. |
| 11 | K | Tipo Manut. | texto/código | 575/575 | 2 | `COR` ou `MEL`; não representa sozinho preventiva × corretiva. |
| 12 | L | Área Manut. | texto/código | 575/575 | 6 | Dimensão principal de setor. |
| 13 | M | Centro Custo | inteiro no arquivo; armazenar `varchar(30)` | 575/575 | 19 | Classificação útil; código sem descrição. |
| 14 | N | Contador | inteiro/decimal | 575/575 | 1 | Sempre `0`. |
| 15 | O | Hora cont. 1 | texto/hora inválida | 575/575 | 1 | Sempre `:`; tratar como nulo. |
| 16 | P | Custo M-D-O | decimal | 575/575 | 22 | 60 valores não zero; faixa 0–76,70. Sem unidade/moeda confirmada. |
| 17 | Q | Custo Troca | decimal | 575/575 | 1 | Sempre `0`. |
| 18 | R | Custo Mater. | decimal | 575/575 | 1 | Sempre `0`. |
| 19 | S | Custo Subst. | decimal | 575/575 | 1 | Sempre `0`. |
| 20 | T | Custo Terc. | decimal | 575/575 | 1 | Sempre `0`. |
| 21 | U | Ult. Man. | data aparente inválida | 575/575 | 1 | Sempre `/  /`; tratar como nulo. |
| 22 | V | Cont. Man. | inteiro/decimal | 575/575 | 1 | Sempre `0`. |
| 23 | W | Prev. Início (data) | data aparente inválida | 575/575 | 1 | Sempre `/  /`; tratar como nulo. |
| 24 | X | Prev. Início (hora) | hora aparente inválida | 575/575 | 1 | Sempre `:`; tratar como nulo. |
| 25 | Y | Prev. Fim (data) | data aparente inválida | 575/575 | 1 | Sempre `/  /`; tratar como nulo. |
| 26 | Z | Prev. Fim (hora) | hora aparente inválida | 575/575 | 1 | Sempre `:`; tratar como nulo. |
| 27 | AA | Real. Início (data) | data ou marcador inválido | 575/575 | 85 | 371 datas e 204 `/  /`. |
| 28 | AB | Real. Início (hora) | hora ou marcador inválido | 575/575 | 79 | 371 horas e 204 `:`. |
| 29 | AC | Real. Fim (data) | data ou marcador inválido | 575/575 | 84 | 371 datas e 204 `/  /`. |
| 30 | AD | Real. Fim (hora) | hora ou marcador inválido | 575/575 | 83 | 371 horas e 204 `:`. |
| 31 | AE | P. In. Man. (data) | data | 575/575 | 69 | “Previsto Início Manutenção”; igual a `Data Origin.` em 575/575. |
| 32 | AF | P. In. Man. (hora) | hora | 575/575 | 333 | Horários entre 07:15 e 16:54. |
| 33 | AG | P. Fim Man. (data) | data | 575/575 | 69 | Igual a `P. In. Man.` em 575/575. |
| 34 | AH | P. Fim Man. (hora) | hora | 575/575 | 333 | Igual à hora de `P. In. Man.` em 575/575. |
| 35 | AI | R. In. Man. (data) | data ou marcador inválido | 575/575 | 86 | 371 datas válidas e 204 `/  /`; campo usado no STATUS. |
| 36 | AJ | R. In. Man. (hora) | hora ou marcador inválido | 575/575 | 78 | 371 horas e 204 `:`. |
| 37 | AK | R. Fim Man. (data) | data ou marcador inválido | 575/575 | 85 | 371 datas válidas e 204 `/  /`. |
| 38 | AL | R. Fim Man. (hora) | hora ou marcador inválido | 575/575 | 83 | 371 horas e 204 `:`. |
| 39 | AM | Pos. Cont. | inteiro/decimal | 575/575 | 1 | Sempre `0`. |
| 40 | AN | Contador 2 | inteiro/decimal | 575/575 | 1 | Sempre `0`. |
| 41 | AO | Término | booleano textual | 575/575 | 2 | `Sim` 371; `Não` 204. |
| 42 | AP | Usuário Alt. | texto | 575/575 | 4 | Usuário do TOTVS; pode ser dado pessoal e está truncado. |
| 43 | AQ | Prioridade | texto/código | 572/575 | 1 + nulo | `ZZZ` 572; 3 vazios. Sem valor analítico atual. |
| 44 | AR | Hora cont. 2 | texto/hora inválida | 575/575 | 1 | Sempre `:`; tratar como nulo. |
| 45 | AS | Situação | texto/código | 575/575 | 2 | `Liberado` 529; `Cancelado` 46. |
| 46 | AT | Centro Trab. | vazio | 0/575 | 0 | Sem dados. |
| 47 | AU | Tipo Retorno | texto/código | 382/575 | 1 + nulo | `S` 382; 193 vazios; sem semântica confirmada. |
| 48 | AV | Ordem Pai | vazio | 0/575 | 0 | Campo potencial futuro; sem dados. |
| 49 | AW | Bem Pai | vazio | 0/575 | 0 | Campo potencial futuro; sem dados. |
| 50 | AX | Substit. O.S | vazio | 0/575 | 0 | Campo potencial futuro; sem dados. |
| 51 | AY | Sol. Serviço | vazio | 0/575 | 0 | Campo potencial futuro; sem dados. |
| 52 | AZ | Cod. Irreg. | inteiro/código ou nulo | 371/575 | 2 + nulo | `2` 367; `4` 4; 204 vazios. Requer dicionário TOTVS. |
| 53 | BA | Terceiro | booleano textual | 575/575 | 1 | Sempre `Não`. |
| 54 | BB | Quant. Repr. | inteiro | 575/575 | 1 | Sempre `0`. |
| 55 | BC | Motivo Repr. | vazio | 0/575 | 0 | Sem dados. |
| 56 | BD | Custo Ferr. | decimal | 575/575 | 1 | Sempre `0`. |
| 57 | BE | O.S. Orig. | vazio | 0/575 | 0 | Campo potencial futuro; sem dados. |

## 3. Campos úteis para PCM

### Essenciais para a primeira versão

- Identidade: `Filial` + `Ordem Serv.`. A chave de negócio recomendada é composta, pois números podem se repetir entre filiais. Embora haja uma só filial agora, não se deve assumir isso no modelo.
- Snapshot: data do relatório extraída do nome, importação de origem e hash do arquivo.
- Equipamento: `Bem` e `Nome do Bem`.
- Classificação: `Serviço`, `Nome Serviço`, `Tipo Manut.`, `Área Manut.` e `Centro Custo`.
- Datas: `Data Origin.`, pares data/hora de `P. In. Man.`, `P. Fim Man.`, `R. In. Man.` e `R. Fim Man.`.
- Estado: `Situação`, `Término` e STATUS tratado.
- Auditoria de origem: `Usuário Alt.` e valores brutos relevantes.

### Úteis sob ressalva

- `Real. Início/Fim` parecem datas gerais de execução, enquanto `R. In. Man./R. Fim Man.` são datas específicas de manutenção. Há diferenças entre as famílias; ambas devem ser preservadas até confirmação funcional.
- `Custo M-D-O` tem dados em apenas 60 OS (10,4%). Pode ser exibido como dado bruto, mas não como custo total confiável.
- `Cod. Irreg.`, `Tipo Retorno`, `Prioridade` e `Terceiro` devem ser guardados, mas só ganham uso gerencial após dicionário/validação com o TOTVS.

## 4. Campos problemáticos ou inconsistentes

1. **Codificação danificada:** acentos já chegam como `�` no XLSX.
2. **Cabeçalhos duplicados:** as colunas de datas usam o mesmo nome para data e hora. O mapeamento não pode ser feito somente por nome.
3. **Marcadores de nulo:** datas vazias usam `/  /` e horas vazias usam `:`; devem ser normalizadas por tentativa estrita de parse, após remoção de espaços comuns, NBSP e outros caracteres Unicode.
4. **Campos previstos gerais vazios:** `Prev. Início` e `Prev. Fim` não têm nenhuma data/hora válida.
5. **Planejamento de manutenção sem duração:** `P. In. Man.` e `P. Fim Man.` são idênticos, inclusive a hora, nas 575 linhas. Não representam uma janela de execução utilizável.
6. **Datas reais muito correlacionadas:** `Real. Início` = `Real. Fim` em 569/575 e `R. In. Man.` = `R. Fim Man.` em 569/575. Duração/tempo de reparo é, portanto, duvidoso.
7. **Colunas constantes ou vazias:** várias não agregam informação neste snapshot, mas não devem ser removidas do contrato de importação porque podem aparecer preenchidas em futuros relatórios.
8. **Códigos tratados como números:** `Filial`, `Ordem Serv.`, `Centro Custo` e às vezes `Bem` devem ser strings para preservar zeros à esquerda e futuras variações.
9. **Prioridade não utilizável:** somente `ZZZ` e três vazios.
10. **Custos incompletos:** somente mão de obra possui alguns valores; todos os demais custos estão zerados.
11. **Data do relatório não aparece em célula:** é inferida do nome. O arquivo diz 21/08/2026, mas a data de origem mais recente é 19/08/2026; isso não é necessariamente erro.
12. **Descrição de bem não é equipamento em todos os casos:** o código mais frequente, `FAB 80 403`, aparece como `MANUTENCAO  PREVENTIVA`, sugerindo cadastro genérico. Rankings devem usar código + nome e permitir separar bens genéricos.

## 5. Valores distintos relevantes

### Área Manut.

| Código | Nome amigável inicial | OS | % |
|---|---|---:|---:|
| MECANI | Mecânica | 375 | 65,2% |
| ELETRI | Elétrica | 110 | 19,1% |
| CALDEI | Caldeiraria | 57 | 9,9% |
| USINAG | Usinagem | 18 | 3,1% |
| OPERAC | Operação | 10 | 1,7% |
| INSTRU | Instrumentação | 5 | 0,9% |

Novos códigos devem ser cadastrados automaticamente com nome amigável inicialmente igual ao código e ficar disponíveis para ajuste administrativo.

### Situação, Término e Tipo Manut.

| Campo | Valor | Quantidade |
|---|---|---:|
| Situação | Liberado | 529 |
| Situação | Cancelado | 46 |
| Término | Sim | 371 |
| Término | Não | 204 |
| Tipo Manut. | COR | 526 |
| Tipo Manut. | MEL | 49 |

`Tipo Manut.` não separa corretamente preventiva e corretiva: 207 OS cujo `Nome Serviço` é preventiva têm tipo `COR`. Para análises preventiva × corretiva, usar uma classificação derivada e versionada a partir de `Serviço`/`Nome Serviço`, nunca apenas `Tipo Manut.`.

### Serviço / Nome Serviço

| Código | Descrição | OS |
|---|---|---:|
| CORMEC | CORRETIVA MECANICA | 171 |
| PREVEN | PREVENTIVA MECANICA | 129 |
| ELEPRE | PREVENTIVA ELETRICA | 78 |
| CORPRO | MANUTENCAO CORRETIVA PROGRAMADA | 43 |
| CALMEL | MELHORIAS CALDEIRARIA | 34 |
| 2425ME | ENTRESSAFRA 24-25 - MECANICA | 22 |
| CORELE | CORRETIVA ELETRICA | 20 |
| CORCAL | MANUTENCAO CORRETIVA CALDEIRARIA | 17 |
| CORUSI | CORRETIVA USINAGEM | 11 |
| COROPE | CORRETIVA OPERACIONAL | 10 |
| PROELE | MANUTENCAO CORRETIVA ELETRICA PROGRAMADA | 9 |
| USIMEL | MELHORIAS DE USINAGEM | 7 |
| MECMAL | MELHORIAS MECANICA | 6 |
| PPRCAL | PARADA PROGRAMADA CALDEIRARIA | 4 |
| COREME | MANUTENCAO CORRETIVA EMERGENGIAL | 3 |
| CORINS | CORRETIVA INSTRUMENTACAO DE INSPECAO | 2 |
| ELEMEL | MELHORIAS ELETRICA | 2 |
| PPRINS | PARADA PROGRAMADA INSTRUMENTACAO | 2 |
| MECOPO | MANUT. CORRETIVA PARADA POR OPORTUNIDADE | 1 |
| PROCAL | PROGRAMADA CORRETIVA CALDERARIA | 1 |
| ELECOP | CORRETIVA INSTRUMENTACAO PROGRAMADA | 1 |
| INSPRO | MANUT. CORRETIVA PARADA POR OPORTUNIDADE | 1 |
| 2425CA | ENTRESSAFRA 24-25 - CALDEIRARIA | 1 |

Há 23 códigos e 22 descrições: `MECOPO` e `INSPRO` compartilham a mesma descrição. Não usar a descrição como chave.

Classificação derivada inicial, sujeita à homologação: preventiva (`PREVEN`, `ELEPRE`, 207 OS), melhoria (`CALMEL`, `USIMEL`, `MECMAL`, `ELEMEL`, 49), corretiva (códigos/descrições explicitamente corretivos, 292) e programada especial/parada/entressafra (27). Como algumas descrições misturam “corretiva” e “programada”, a taxonomia deve ser configurável e manter o código original.

### Outras classificações

- Centro de custo: 19 códigos. Maiores volumes: `3101004` 166, `3101001` 104, `3101005` 95, `4101004` 46, `4101002` 45, `3101008` 28, `4101003` 26 e `4101005` 22.
- Tipo O.S.: somente `Bem` (575).
- Prioridade: `ZZZ` em 572 e vazio em 3.
- Tipo Retorno: `S` em 382 e vazio em 193.
- Cod. Irreg.: `2` em 367, `4` em 4 e vazio em 204.
- Terceiro: `Não` em 575.

## 6. Validação da regra de STATUS

Regra validada na ordem solicitada:

```text
se Situação normalizada = "cancelada" (aceitando a forma legada "cancelado") => CANCELADA
senão, se Término normalizado = "sim" => CONCLUÍDA
senão => EM ANDAMENTO
```

Normalização recomendada: `trim` Unicode, remoção de NBSP/caracteres invisíveis, comparação sem diferença de caixa e acento para campos de controle, e parsing estrito de data. `/  /`, vazio, `:`, zero e datas impossíveis tornam-se `null`.

| Situação | Término | Início real válido | STATUS | Quantidade |
|---|---|---:|---|---:|
| Liberado | Sim | sim | CONCLUÍDA | 371 |
| Liberado | Não | não | EM ANDAMENTO | 158 |
| Cancelado | Não | não | CANCELADA | 46 |

Resultado pela regra v2: 575/575 linhas classificadas em três estados. `R. In. Man.` e `R. Fim Man.` são datas reais preservadas para análise, mas não participam da classificação. `P. In. Man.` e `P. Fim Man.` representam a criação da OS no TOTVS e também não participam do STATUS. Toda OS não cancelada com `Término = Não` fica EM ANDAMENTO, mesmo sem início real preenchido.

## 7 e 8. Proposta de tabelas MySQL e relacionamentos

### `report_imports` (`importacoes_relatorios`)

- `id bigint unsigned` PK
- `file_name varchar(255)`, `file_path varchar(1024)`
- `report_date date`
- `file_hash char(64)` com índice único
- `file_size bigint unsigned`, `sheet_name varchar(100)`
- `status enum('processing','success','failed','rejected')`
- `rows_read int unsigned`, `rows_imported int unsigned`, `warning_count int unsigned`, `error_count int unsigned`
- `started_at datetime(6)`, `finished_at datetime(6)`, `error_message text`
- `header_signature char(64)`, `metadata json`, `created`, `modified`

Restrições: `unique(file_hash)`; opcionalmente `unique(report_date, header_signature)` somente se o negócio proibir dois arquivos válidos para a mesma data. É melhor aceitar uma reexportação como nova tentativa controlada do que descartá-la silenciosamente.

### `maintenance_areas` (`areas_manutencao`)

- `id`, `source_code varchar(30)` único, `display_name varchar(100)`, `slug varchar(120)` único
- `active boolean`, `created`, `modified`

### `equipment` (`equipamentos`)

- `id`, `branch_code varchar(10)`, `source_code varchar(100)`, `name varchar(255)`
- `is_generic boolean default false`, `active boolean`, `created`, `modified`
- único (`branch_code`, `source_code`)

### `services` (`servicos`)

- `id`, `source_code varchar(30)` único, `name varchar(255)`
- `pcm_category varchar(30) null` (`corrective`, `preventive`, `improvement`, `planned_special`, `other`)
- `classification_version int`, `active`, `created`, `modified`

Mantém a classificação derivada configurável e auditável. Se códigos puderem variar por filial, incluir filial na chave.

### `cost_centers` (`centros_custo`)

- `id`, `source_code varchar(30)` único, `name varchar(255) null`, `active`, `created`, `modified`

### `work_orders` (`ordens_servico`)

- `id bigint unsigned` PK
- `branch_code varchar(10)`, `source_order_number varchar(30)`
- `equipment_id bigint unsigned null`
- `first_seen_report_date date`, `last_seen_report_date date`
- `created`, `modified`
- único (`branch_code`, `source_order_number`)

Esta tabela representa a identidade duradoura da OS, não seu estado mutável.

### `work_order_snapshots` (`ordens_servico_snapshots`)

- `id bigint unsigned` PK
- `work_order_id` FK, `report_import_id` FK, `report_date date`
- FKs opcionais: `maintenance_area_id`, `equipment_id`, `service_id`, `cost_center_id`
- códigos/textos originais: plano, tipo OS, sequência, tipo manutenção, situação, término, prioridade, usuário, retorno, irregularidade, terceiro e relacionamentos de OS/bem
- datas normalizadas: `origin_date date`, `general_planned_start/end datetime null`, `general_actual_start/end datetime null`, `maintenance_planned_start/end datetime null`, `maintenance_actual_start/end datetime null`
- `treated_status varchar(20)`, `status_rule_version smallint unsigned`
- custos `decimal(15,2)` e contadores pertinentes
- `raw_payload json` para preservar as 57 colunas e permitir auditoria/evolução do layout
- `source_row_number int unsigned`, `row_hash char(64)`, `validation_warnings json`, `created`, `modified`
- único (`report_import_id`, `work_order_id`)
- índices (`report_date`, `treated_status`), (`maintenance_area_id`, `report_date`), (`equipment_id`, `report_date`), (`service_id`, `report_date`)

O snapshot deve guardar os valores descritivos necessários ou suas chaves dimensionais como estavam na data. Para impedir que renomear uma dimensão altere um relatório histórico, manter no snapshot também os códigos/nomes de origem relevantes ou criar dimensões com versionamento. Para a primeira versão, código/nome no snapshot mais FK para navegação é a opção mais simples e auditável.

### Relacionamentos

```text
report_imports 1 ── N work_order_snapshots N ── 1 work_orders
work_orders N ── 1 equipment
work_order_snapshots N ── 1 maintenance_areas
work_order_snapshots N ── 1 equipment
work_order_snapshots N ── 1 services
work_order_snapshots N ── 1 cost_centers
```

Não criar uma tabela física separada de “snapshot atual”. Uma view/consulta seleciona a última importação bem-sucedida. Isso evita divergência entre histórico e estado atual.

## 9. Estratégia de snapshots diários

1. A unidade de snapshot é uma OS por importação/data de relatório.
2. Cada importação bem-sucedida é imutável; correções entram como nova importação controlada, nunca `UPDATE` destrutivo do histórico.
3. A identidade da OS usa (`filial`, `ordem_servico`); a restrição do snapshot usa (`report_import_id`, `work_order_id`).
4. `report_date` vem inicialmente do padrão estrito `Relatorio_OS_YYYY-MM-DD.xlsx`. Nome inválido rejeita ou exige data explícita no upload manual futuro.
5. `file_hash` impede reprocessamento do mesmo conteúdo; um lock impede duas execuções concorrentes.
6. O dashboard atual usa a importação `success` com maior `report_date`; em empate, é necessária uma política de versão/aprovação, não apenas “última hora”.
7. Evolução temporal agrupa por `report_date` e conta cada OS uma vez dentro de cada snapshot. Nunca somar snapshots para obter carteira.
8. O desaparecimento de uma OS entre relatórios não significa conclusão. Ele deve ser tratado como “ausente no snapshot” e só ganhar semântica após confirmação do processo TOTVS.
9. Guardar `row_hash` permite detectar quais OS mudaram entre snapshots sem perder as repetições legítimas.
10. Uma retenção futura pode compactar arquivos físicos, mas não deve remover snapshots sem política formal de auditoria.

## 10. Arquitetura proposta das classes CakePHP

### Command

- `ImportReportsCommand`: orquestra varredura, lock e processamento; devolve código de saída apropriado ao Agendador do Windows.

### Serviços de importação

- `ReportFileLocator`: encontra arquivos `.xlsx` na pasta configurada.
- `ReportFilenameParser`: valida nome e extrai `report_date`.
- `ReportDuplicateChecker`: verifica hash/importações existentes.
- `TotvsWorkbookReader`: abre `sclxd280` com PhpSpreadsheet e produz linhas sem regra de negócio.
- `TotvsHeaderValidator`: valida aba, quantidade, posições e aliases de cabeçalho.
- `TotvsRowMapper`: mapeia as 57 posições para DTO tipado.
- `TotvsValueNormalizer`: normaliza strings, códigos, booleanos, datas, horas e marcadores nulos.
- `WorkOrderStatusResolver`: única implementação da regra de STATUS, com versão explícita.
- `ReportRowValidator`: valida número da OS, domínios e coerência.
- `ReportImportService`: controla transação, dimensões, OS, snapshots e contadores.
- `ImportLockService`: impede concorrência entre agendador e futuro upload manual.

### DTOs/resultados

- `ReportRowDto`, `ImportContext`, `RowValidationResult`, `ImportResult`.

### Model/Table/Entity

- `ReportImportsTable`, `WorkOrdersTable`, `WorkOrderSnapshotsTable`, `MaintenanceAreasTable`, `EquipmentTable`, `ServicesTable`, `CostCentersTable` e respectivas Entities.
- Finders reutilizáveis: `findLatestSnapshot()`, `findForSnapshotDate()`, `findByArea()`, `findStatusCounts()`.

### Consulta e indicadores

- `CurrentSnapshotProvider`: resolve de forma única qual importação alimenta o painel atual.
- `PcmIndicatorService`: calcula KPIs com denominadores e filtros consistentes.
- `WorkOrderHistoryService`: monta a evolução de cada OS.
- `PcmClassificationService`: aplica a taxonomia configurada de serviço.

Controllers futuros apenas recebem parâmetros, autorizam, chamam serviços e formatam resposta. Templates/JavaScript não recalculam indicadores de negócio.

## 11. Fluxo da importação automática

1. Agendador executa `bin/cake importar_relatorios` periodicamente.
2. Command adquire lock exclusivo e lê o caminho de configuração/environment.
3. Localizador ignora arquivos temporários e arquivos ainda em gravação (tamanho/data estáveis em duas verificações ou renomeação atômica acordada).
4. Para cada arquivo, calcula SHA-256 e consulta `report_imports`.
5. Parser valida o nome e extrai a data do relatório.
6. Cria registro `processing` fora da transação de dados, para auditoria da tentativa.
7. Abre o XLSX, exige a aba `sclxd280`, valida cabeçalhos/posições e recusa arquivo vazio/corrompido.
8. Lê linhas em modo econômico de memória, preservando número da linha e payload bruto.
9. Normaliza e valida. OS sem número ou duplicada no arquivo é erro crítico; datas opcionais inválidas viram nulo + alerta, conforme política documentada.
10. Calcula STATUS via `WorkOrderStatusResolver` e `row_hash`.
11. Em uma transação por arquivo, faz upsert controlado das dimensões/OS e insere todos os snapshots.
12. Confere contagens: linhas lidas = importadas + rejeitadas conforme política. Para o modelo inicial recomendado, qualquer erro estrutural/identificador causa rollback integral.
13. Commit; marca a importação `success`, com quantidades e avisos. Em falha, rollback e marca `failed/rejected`, sem afetar arquivos anteriores.
14. Registra log estruturado sem expor desnecessariamente nomes de usuário/dados pessoais.
15. Libera lock e segue para o próximo arquivo; uma falha isolada não bloqueia os demais.

Validações mínimas automatizadas: extensão e assinatura ZIP, hash, nome/data, aba, cabeçalho completo, arquivo não vazio, tipos parseáveis, OS presente, unicidade da OS dentro do arquivo, domínios conhecidos com política para novos valores, contagens finais e integridade referencial.

## 12. Indicadores calculáveis com segurança

No snapshot atual, e depois por área/equipamento/serviço/centro de custo:

- Total de OS: 575.
- Total de OS ativas: 529 (`371 + 158`); canceladas ficam fora do total.
- Concluídas: 371.
- Em andamento: 158.
- Canceladas: 46.
- Eficiência: `371 / (371 + 158) = 70,13%`; canceladas ficam fora do denominador.
- Distribuição de STATUS, área, situação, término, serviço, tipo de manutenção e centro de custo.
- Top equipamentos por quantidade de OS, usando `Bem` como chave e exibindo `Nome do Bem`, com aviso/categoria para bens genéricos.
- Carteira por data de origem e idade das OS em andamento, usando `report_date - Data Origin.`. Neste arquivo, são 158 OS em andamento; a data de origem está 100% preenchida.
- Faixas de idade/backlog (0–7, 8–15, 16–30, 31–60, >60 dias), deixando explícito que é idade desde a origem, não atraso contra prazo.
- Quantidade de OS por centro de custo e ranking, sem nomes até obter o cadastro.
- Classificação preventiva/corretiva/melhoria somente com tabela de mapeamento homologada de `Serviço`; o código e a versão da regra devem ser auditáveis.
- Com dois ou mais snapshots: evolução da carteira e dos estados por data, transições de STATUS por OS, dias no mesmo estado, reincidência de equipamento por novas OS distintas e OS ausentes/reaparecidas, sempre sem somar snapshots.

Os cinco KPIs de estado fecham: `371 + 46 + 158 + 0 = 575`.

## 13. Indicadores que não podem ser calculados com segurança agora

- **Atraso contra prazo previsto:** os campos gerais previstos estão vazios e início/fim previstos de manutenção são idênticos.
- **Previsto × realizado e aderência ao planejamento:** não há janela prevista confiável.
- **Tempo de atendimento, MTTR ou duração da manutenção:** datas de início/fim reais são quase sempre iguais e a semântica entre `Real.` e `R.` não está confirmada.
- **MTBF/disponibilidade/confiabilidade:** faltam horas de operação, falhas confirmadas, período em serviço e cadastro confiável de ativos.
- **Custo total, custo por equipamento ou orçamento × realizado:** cinco categorias estão totalmente zeradas; mão de obra é parcial e não tem unidade/moeda confirmada.
- **Produtividade ou horas de mão de obra:** custo não equivale a horas e os campos de contador não têm dados.
- **SLA/tempo de resposta:** não há data de solicitação, atendimento e critérios de SLA inequivocamente definidos.
- **Prioridade/criticidade:** `Prioridade` é praticamente constante e não existe criticidade do ativo.
- **Performance de terceiros:** `Terceiro` é sempre `Não`.
- **Reprogramação/retrabalho:** quantidade é sempre zero e motivo está vazio.
- **Hierarquia de OS, substituições e ordens originadoras:** campos correspondentes estão vazios.
- **Pareto financeiro:** custos incompletos. Pareto por quantidade é possível, mas “80% das falhas” exige homologar o que constitui falha e excluir bens genéricos.
- **Taxa de conclusão diária real:** um único snapshot só fornece o estado atual. Mesmo com histórico, mudança para concluído mede transição observada entre relatórios, não necessariamente a hora exata da conclusão.
- **Demanda criada por dia sem ressalva:** `Data Origin.` aparenta ser adequada, mas sua semântica deve ser confirmada com o responsável TOTVS antes de chamá-la formalmente de data de criação.

## Decisões recomendadas antes da Fase 2

1. Confirmar com o responsável TOTVS a semântica das quatro famílias de datas (`Prev.`, `Real.`, `P.` e `R.`) e por que início/fim são iguais.
2. Confirmar se `Data Origin.` é a data de abertura/criação da OS.
3. Homologar a taxonomia configurável dos 23 códigos de serviço.
4. Obter dicionário dos códigos `Tipo Manut.`, `Cod. Irreg.`, `Tipo Retorno` e `Prioridade`.
5. Confirmar se a chave global é realmente filial + número da OS e se números podem ser reutilizados.
6. Definir política para mais de um arquivo referente à mesma data e para OS que desaparecem do relatório.
7. Corrigir, se possível, a exportação TOTVS para UTF-8/acentuação íntegra.

**Conclusão da Fase 1:** os KPIs básicos de carteira e STATUS são consistentes neste arquivo. A arquitetura deve preservar o dado bruto, separar identidade de OS de seus snapshots e versionar regras derivadas. Indicadores de prazo, duração e custo devem permanecer desabilitados até que a origem forneça ou valide dados adequados.
