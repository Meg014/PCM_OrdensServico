# Pesquisa e detalhes: fim real de manutenção

## Causa

A pesquisa e o Excel geral passaram a exibir `TJ_DTPRFIM` como fim real, enquanto os detalhes exibiam `TJ_DTMRFIM`. São conceitos distintos. A ausência da data geral não significa ausência de fim da execução da manutenção.

## Correção

`App\Service\Protheus\OrderDates` centraliza os pares de data/hora consumidos pela pesquisa, pelos detalhes e pelo Excel:

| Papel | Data | Hora |
| --- | --- | --- |
| Início real da manutenção | TJ_DTMRINI | TJ_HOMRINI |
| Fim real da manutenção | TJ_DTMRFIM | TJ_HOMRFIM |
| Início real geral | TJ_DTPRINI | TJ_HOPRINI |
| Fim real geral | TJ_DTPRFIM | TJ_HOPRFIM |

O Excel geral identifica a coluna como **Fim real da manutenção**, evitando chamar a execução de fechamento administrativo. Não há fallback entre os pares. A regra homologada dos indicadores continua usando TJ_DTPRFIM; esta correção trata da exibição da execução, sem homologar outro campo como evento administrativo de encerramento.

## Validação

- SELECT direto da O.S. **005810 / filial 01**: pesquisa e detalhes apontam para **28/10/2026**, início **07:30**, fim **17:00**. TJ_DTPRFIM continua vazio; situação L e término S preservados.
- As demais 64 O.S. sem fim geral e com fim de manutenção preenchido têm o mesmo padrão. A comparação da base também confirmou 223 pares preenchidos com datas diferentes; a pesquisa agora representa o fim de manutenção dos detalhes, em vez da data geral.
- A reconciliação da TV foi executada novamente por SELECT: fechadas Safra **2.186**, Entressafra **52**, abertas **1.556**, **zero divergências nas 12 telas**. O grupo sem TJ_DTPRFIM continua com **65 O.S.**, sem inclusão automática nos indicadores.
- **187 testes, 5.410 asserções passaram**, incluindo renderização da pesquisa da 005810, equivalência com detalhes/Excel, datas distintas, ausência de fallback, status, filtros, indicadores, cancelamento e demais regressões Protheus. Sintaxe dos quatro arquivos PHP de produção verificada; `git diff --check` sem erros.

Sem alteração de dados TOTVS, sem deploy e sem alterações no Ubuntu.
