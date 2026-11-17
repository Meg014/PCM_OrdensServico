# O.S. concluídas sem Real. Fim — 17/11/2026

Consulta direta ao Protheus exclusivamente SELECT. Situação L e TJ_TERMINO S mantidos; nenhuma O.S. reclassificada como aberta.

Relatório detalhado: [os-concluidas-sem-data-2026-11-17.xlsx](os-concluidas-sem-data-2026-11-17.xlsx). Números e filiais são texto, com zeros à esquerda. Serviço Executado completo está na planilha; evidências brutas e metadados no JSON correspondente.

## Resultados

| Verificação | Quantidade |
| --- | ---: |
| O.S. únicas | 65 |
| TJ_DTPRFIM vazio | 65 |
| TJ_DTPRFIM NULL | 0 |
| Datas inválidas / outros formatos | 0 |
| Real. Início geral válido (TJ_DTPRINI) | 0 |
| Início real manutenção válido (TJ_DTMRINI) | 65 |
| Fim real manutenção válido (TJ_DTMRFIM) | 65 |
| Com apontamentos não excluídos | 65 |
| Com Serviço Executado legível (STQ) | 1 |

Em todas as linhas vazias foram preservados tamanho/hex para distinguir espaço de NULL ou data inválida. A consulta atual encontrou oito espaços (hex 2020202020202020), não um erro de conversão de uma data preenchida.

### Antiguidade pela Data de origem

- 2024: 61 O.S.
- 2025: 1 O.S.
- 2026: 3 O.S.

O ano de origem não equivale ao ano de encerramento. Os registros do ano corrente devem ser conferidos junto com os antigos; não se trata somente de legado de 2024.

## Causa observável e limites

O status de conclusão está preenchido, mas os campos reais gerais não foram alimentados nestas O.S.; os campos reais de manutenção e os apontamentos possuem registros. Esse é o motivo observável da exclusão do indicador baseado em TJ_DTPRFIM. Não foi possível provar por SELECT qual rotina, integração ou ação do usuário deixou os campos gerais vazios.

Serviço Executado foi lido da fonte STQ já usada pelo PCM, com a mesma normalização existente. Campo bruto não nulo com espaços/controle não foi contado como texto executado. A ausência de texto não altera o status.

## Existe outra data confiável de encerramento?

TJ_DTMRFIM é candidato como fim efetivo da manutenção: existe nas O.S. investigadas. Entretanto, fim de execução e evento de encerramento não são comprovadamente o mesmo evento. Não foi homologado um substituto automático para TJ_DTPRFIM.

Na população completa de 4853 O.S. L/S, há 4565 pares de datas gerais/manutenção iguais, 223 pares preenchidos diferentes e 65 casos sem fim geral mas com fim manutenção. Portanto, os campos não são intercambiáveis.

No grupo investigado, TJ_DTMRFIM coincide com o último fim de apontamento em 60 O.S. e difere em 5. Também não é seguro usar MAX(TL_DTFIM) como encerramento.

Metadados visíveis: STJ010 não é tabela temporal; CDC e Change Tracking estão desativados. Existe trigger STJ010_STAMP habilitada, mas sua definição não está visível para a conexão. Carimbos técnicos de registro não provam mudança para status concluído. Não foram visíveis dicionários SX3 ou uma tabela de histórico de encerramento; isso não prova sua inexistência em outros ambientes/permissões.

**Pendência PCM/TOTVS:** conferir a rotina que finaliza a O.S. e o dicionário funcional, especialmente as O.S. recentes; validar se TJ_DTMRFIM deve ser a referência funcional dos indicadores ou se deve haver registro dedicado do evento de fechamento. Qualquer mudança dessa regra exige homologação explícita, sem copiar datas automaticamente.

## Datas alternativas divergentes

| O.S. | Filial | TJ_DTMRFIM | Último fim de apontamento |
| --- | --- | --- | --- |
| 000078 | 01 | 2024-06-11 | 2024-06-12 |
| 000079 | 01 | 2024-06-17 | 2024-06-18 |
| 000080 | 01 | 2024-06-17 | 2024-06-18 |
| 000082 | 01 | 2024-06-18 | 2024-06-20 |
| 000083 | 01 | 2024-06-19 | 2024-06-20 |

## Relação para conferência

| O.S. | Filial | Equipamento | Serviço / nome original | Área original | Situação | TJ_TERMINO | TJ_DTPRFIM | Origem | Apontamentos | Executado legível |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | ---: | --- |
| 000001 | 01 | TUR 60.001 | PREMEC / PREVENTIVA MECANICA | MECANI | L | S | [8 espaços] | 2024-02-23 | 2 | Não |
| 000004 | 01 | TUR 60.001 | CORMEC / CORRETIVA MECANICA | MECANI | L | S | [8 espaços] | 2024-03-11 | 5 | Não |
| 000008 | 01 | TUR 60.001 | PREMEC / PREVENTIVA MECANICA | MECANI | L | S | [8 espaços] | 2024-02-24 | 2 | Não |
| 000046 | 01 | 000186 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-08 | 4 | Não |
| 000047 | 01 | 000188 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-09 | 4 | Não |
| 000048 | 01 | BCE 50 069 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-09 | 4 | Não |
| 000049 | 01 | 000196 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-13 | 14 | Não |
| 000051 | 01 | 000184 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-15 | 4 | Não |
| 000052 | 01 | 000080 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-16 | 4 | Não |
| 000053 | 01 | 000197 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-17 | 8 | Não |
| 000054 | 01 | 000108 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-22 | 4 | Não |
| 000055 | 01 | 000118 | COREME / MANUTENCAO CORRETIVA EMERGENGIAL | MECANI | L | S | [8 espaços] | 2024-05-22 | 4 | Não |
| 000056 | 01 | 000017 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-22 | 4 | Não |
| 000057 | 01 | 000183 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-22 | 4 | Não |
| 000058 | 01 | 000061 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-22 | 4 | Não |
| 000059 | 01 | 000073 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-23 | 9 | Não |
| 000061 | 01 | PNR 20 001 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-23 | 14 | Não |
| 000062 | 01 | 000018 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-23 | 34 | Não |
| 000066 | 01 | 000017 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-27 | 4 | Não |
| 000067 | 01 | 000017 | COREME / MANUTENCAO CORRETIVA EMERGENGIAL | MECANI | L | S | [8 espaços] | 2024-05-27 | 4 | Não |
| 000068 | 01 | 000118 | COREME / MANUTENCAO CORRETIVA EMERGENGIAL | MECANI | L | S | [8 espaços] | 2024-05-27 | 4 | Não |
| 000069 | 01 | SON 10 001 | COREME / MANUTENCAO CORRETIVA EMERGENGIAL | MECANI | L | S | [8 espaços] | 2024-05-27 | 6 | Não |
| 000070 | 01 | 000086 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-29 | 11 | Não |
| 000071 | 01 | 000086 | COREME / MANUTENCAO CORRETIVA EMERGENGIAL | MECANI | L | S | [8 espaços] | 2024-05-29 | 4 | Não |
| 000074 | 01 | PNR 20 001 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-30 | 10 | Não |
| 000075 | 01 | ETM 20 003 | COREME / MANUTENCAO CORRETIVA EMERGENGIAL | MECANI | L | S | [8 espaços] | 2024-06-04 | 14 | Não |
| 000076 | 01 | 000211 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-06-07 | 14 | Não |
| 000077 | 01 | 000155 | COREME / MANUTENCAO CORRETIVA EMERGENGIAL | MECANI | L | S | [8 espaços] | 2024-06-07 | 6 | Não |
| 000078 | 01 | 000101 | COREME / MANUTENCAO CORRETIVA EMERGENGIAL | MECANI | L | S | [8 espaços] | 2024-06-11 | 9 | Não |
| 000079 | 01 | 000212 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-06-17 | 4 | Não |
| 000080 | 01 | 000030 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-06-17 | 4 | Não |
| 000081 | 01 | 000116 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-06-19 | 6 | Não |
| 000082 | 01 | 000143 | COREME / MANUTENCAO CORRETIVA EMERGENGIAL | MECANI | L | S | [8 espaços] | 2024-06-19 | 12 | Não |
| 000083 | 01 | 000213 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-06-19 | 6 | Não |
| 000084 | 01 | 000143 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-06-20 | 8 | Não |
| 000085 | 01 | ETB 10 001 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-06-21 | 4 | Não |
| 000086 | 01 | HIL 10 001 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-06-21 | 4 | Não |
| 000087 | 01 | 000084 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-06-21 | 4 | Não |
| 000088 | 01 | 000029 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-06-24 | 4 | Não |
| 000089 | 01 | 000106 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-06-24 | 4 | Não |
| 000090 | 01 | 000119 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-06-25 | 6 | Não |
| 000091 | 01 | ETM 20 004 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-06-26 | 12 | Não |
| 000092 | 01 | SON 10 001 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-06-26 | 14 | Não |
| 000094 | 01 | 000216 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-06-27 | 14 | Não |
| 000095 | 01 | 000186 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-05-08 | 4 | Não |
| 000096 | 01 | 000118 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-07-02 | 18 | Não |
| 000097 | 01 | 000118 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-07-02 | 4 | Não |
| 000098 | 01 | BCE 40 054 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-07-02 | 6 | Não |
| 000099 | 01 | 000029 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-07-03 | 4 | Não |
| 000100 | 01 | MEL 10 141 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-07-03 | 4 | Não |
| 000101 | 01 | 000018 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-07-03 | 4 | Não |
| 000102 | 01 | CFE 40 001 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-07-03 | 4 | Não |
| 000103 | 01 | 000021 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-07-05 | 6 | Não |
| 000104 | 01 | 000021 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-07-09 | 4 | Não |
| 000105 | 01 | 000229 | COREME / MANUTENCAO CORRETIVA EMERGENGIAL | MECANI | L | S | [8 espaços] | 2024-05-02 | 2 | Não |
| 000107 | 01 | 000145 | COREME / MANUTENCAO CORRETIVA EMERGENGIAL | MECANI | L | S | [8 espaços] | 2024-07-05 | 6 | Não |
| 000108 | 01 | 000044 | COREME / MANUTENCAO CORRETIVA EMERGENGIAL | MECANI | L | S | [8 espaços] | 2024-07-10 | 4 | Não |
| 000109 | 01 | 000106 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-07-11 | 10 | Não |
| 000137 | 01 | CFE 40 001 | CORELE / CORRETIVA ELETRICA | ELETRI | L | S | [8 espaços] | 2024-08-01 | 4 | Não |
| 000138 | 01 | CFE 40 003 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-08-01 | 2 | Não |
| 000306 | 01 | BCE 40 062 | CORPRO / MANUTENCAO CORRETIVA PROGRAMADA | MECANI | L | S | [8 espaços] | 2024-11-04 | 2 | Não |
| 003108 | 01 | RDL 80 005 | CORMEC / CORRETIVA MECANICA | MECANI | L | S | [8 espaços] | 2025-11-24 | 2 | Não |
| 004105 | 01 | BCE 80 018 | CORMEC / CORRETIVA MECANICA | MECANI | L | S | [8 espaços] | 2026-02-25 | 1 | Não |
| 004257 | 01 | ETM 50 01 | CORCAL / MANUTENCAO CORRETIVA CALDEIRARIA | CALDEI | L | S | [8 espaços] | 2026-03-25 | 2 | Não |
| 005810 | 01 | TER 20 002 | ESMECA / ENTRESSAFRA | MECANI | L | S | [8 espaços] | 2026-09-30 | 4 | Sim |

Nenhuma alteração no TOTVS, no Ubuntu ou nas regras dos indicadores. Sem deploy.
