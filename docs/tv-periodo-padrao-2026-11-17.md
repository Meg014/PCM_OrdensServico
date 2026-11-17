# TV: concluídas com período padrão — 17/11/2026

**SUPERADO por orientação posterior do usuário:** a [regra definitiva](regra-definitiva-fechadas-2026-11-17.md) conta todas as O.S. com TJ_TERMINO S não canceladas quando não há período. O ciclo automático descrito abaixo não é mais aplicado pela TV.

## Causa e correção

O serviço substituía os cartões de concluídas por `null` quando `date_start`/`date_end` não eram informados. O JavaScript exibia “—”. Esse bloqueio havia sido introduzido para não apresentar todo o histórico como período atual, mas faltava um padrão automático.

Sem datas manuais, as concluídas agora usam o **ano operacional atual até hoje, no fuso America/Sao_Paulo**. Nesta conferência: **01/01/2026–17/11/2026**. É um padrão técnico explícito, não um calendário agrícola presumido a partir do código do serviço. Um início oficial de ciclo pode ser definido em `config/.env` com `PCM_CURRENT_CYCLE_START=AAAA-MM-DD` (`Pcm.currentCycleStart`). O fim padrão permanece hoje; anos anteriores e datas futuras ficam fora.

O filtro automático atua somente nas concluídas. **As abertas conservam a carteira ativa anterior**, incluindo Entressafra antiga e sem data planejada, conforme orientação do usuário. Ao informar as duas datas manualmente, o filtro existente continua funcionando: concluídas por Real. Fim; abertas por origem. A atualização automática preserva essa distinção e não converte o padrão em filtro manual.

Aplica-se à apresentação direta do Protheus e à apresentação legada por importação. Layout, classificação por Nome Serviço, agrupamento de áreas e regras de cancelamento foram preservados.

## Campo confirmado no Protheus

O mapeamento existente identifica **Real. Fim = TJ_DTPRFIM**, associado a `general_actual_end` na importação. `TJ_DTMRFIM` é R. Fim Man., campo adicional; não é usado como substituto.

A consulta de metadados no SQL Server confirmou `TJ_DTPRFIM` e `TJ_DTMRFIM` como `varchar(8)`, convertidos pelo formato 112. `TJ_TERMINO` e `TJ_SITUACA` são `varchar(1)`: o primeiro é indicador S/N, não uma data.

Concluídas exigem situação L e término S, com Real. Fim válido dentro do intervalo. Canceladas não entram. Conclusão inválida/ausente não recebe fallback para origem, planejamento ou outro campo.

## Validação direta — somente SELECT

| Indicador da TV | Safra | Entressafra |
| --- | ---: | ---: |
| Fechadas no período padrão | 2.186 | 52 |
| Abertas, carteira preservada | 194 | 1.362 |

**Zero divergências** entre a consulta de reconciliação e os quatro cartões nas **12 telas** geral/setoriais. A comparação independente da carteira confirmou **1.556 abertas antes e depois** da alteração. As 65 concluídas sem Real. Fim válido permanecem fora do total por período e disponíveis para conferência, sem alteração automática.

- SQL reproduzível: `docs/sql/pcm-tv-default-cycle.sql` — SELECT de metadados e SELECT agregado com o mesmo critério de data da TV.
- Comando de conferência: `php bin/tv-default-audit.php`.
- Resultado local: `tmp/tv-default-audit.json`.
- **210 testes, 5.610 asserções passaram**, incluindo virada do ano, início de ciclo configurado, separação de fechadas históricas/futuras, conclusão ausente, preservação de abertas antigas, filtros manuais, cancelamento, áreas, rotas, Serviço Executado, checklist, pesquisa, exportações e acesso da TV.

Sem deploy e sem alterações no Ubuntu, nos dados ou na estrutura do TOTVS.
