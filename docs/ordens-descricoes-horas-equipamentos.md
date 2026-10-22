# Ordens: descrições, horas e pesquisa de equipamentos

Implementação e verificação em 22/10/2026. Escopo: `/pcm/ordens`, detalhes da O.S.
e preservação do filtro de equipamento nas exportações dessas telas.

## Fontes confirmadas na instalação

| Prioridade | Fonte real | Resultado |
| --- | --- | --- |
| 1 | `dbo.STJ010.TJ_OBSERVA` | Observação original da O.S., já acessível |
| 2 | `dbo.STL010.TL_OBSERVA` | Observações dos apontamentos ativos, acessíveis |
| 3 | Tarefas vinculadas | Pendente de permissão e confirmação de campos/vínculos |
| 4 | `dbo.ST4010.T4_DESCRIC` | Campo detalhado confirmado nos metadados, `varbinary`, acessível |

O campo binário textual é convertido com `CONVERT(VARCHAR(MAX), ...)` no SQL
Server, usando a mesma abordagem das observações existentes. Textos inválidos,
controles binários e conteúdo vazio são descartados; quebras de linha e textos
legíveis são preservados. `T4_NOME` continua sendo apenas o nome do serviço e
nunca entra na escolha da descrição detalhada destas telas.

A primeira observação útil de apontamento segue data, hora e identificador do
registro. O detalhe apresenta todas as observações úteis e seus contextos, mesmo
quando outra fonte tem precedência. Textos iguais aparecem uma vez, com as
origens, datas e profissionais reunidos. Nenhum texto genérico foi criado.

Os cadastros de serviço mantêm a prioridade filial exata > compartilhada; uma
filial diferente não é utilizada. O lote de descrições usa filial + O.S., somente
após a paginação, com uma consulta adicional para o lote inteiro. Não altera a
quantidade, ordem, identidade ou classificação das O.S. O lote máximo é de 1.000
O.S.; nenhuma chamada individual por O.S. é feita pela listagem.

## Tarefas: solicitação de leitura à TI

A consulta fixa `OrderDescriptionQueries::PERMISSIONS` confirmou:

- `HAS_PERMS_BY_NAME('dbo.TT9010', 'OBJECT', 'SELECT') = 0`.
- `HAS_PERMS_BY_NAME('dbo.ST5010', 'OBJECT', 'SELECT') = 0`.
- `HAS_PERMS_BY_NAME('dbo.ST4010', 'OBJECT', 'SELECT') = 1`.

Os metadados visíveis não expõem os campos das duas tabelas de tarefas. O projeto
não possui outra consulta autorizada para suas descrições. Não foram tentados
novos SELECT de dados nesses objetos, mudanças de conta, caminhos alternativos
ou contornos do erro 229 anteriormente registrado.

**Pedido para encaminhar à TI:** conceder somente SELECT à conta da integração
PCM em `dbo.TT9010` e `dbo.ST5010`, ou fornecer uma view oficial de leitura restrita
com descrição, filial e chaves de vínculo com a O.S./tarefa. Confirmar os campos
físicos e o relacionamento antes de ativar essa terceira fonte. Não se solicita
escrita, mudança de estrutura ou acesso global à base. A solicitação está
documentada; não foi enviada a terceiros automaticamente.

## Apresentação da mão de obra

Somente apontamentos de mão de obra com unidade `H` recebem apresentação de
tempo. A duração usa as datas e horários completos do apontamento, inclusive
viradas de dia. A quantidade original permanece no payload e na indicação
“Registrado no TOTVS”. Materiais e outras unidades mantêm sua quantidade original.

Exemplo conferido em teste: 21/09/2026, 07:30–08:15, quantidade `0.75 H` →
`45 min (0,75 h)`. Também foram conferidos 30 min, 1h, 1h30, 2h e virada de data.

O cálculo mede tempo entre os horários; não deduz intervalos ou regras de
remuneração. Divergências aparecem explicitamente com ambas as informações.
Arredondamentos decimais do TOTVS são considerados: 65 minutos correspondem a
`1.08 H` na precisão de duas casas. Quantidades com maior precisão usam essa
precisão na comparação, com tolerância mínima de um segundo. Datas ausentes,
inválidas ou término anterior ao início impedem o cálculo e geram indicação de
duração indisponível; não se presume uma virada de dia sem a data final.

## Bem / Equipamento

Foi consultada, sem alterações, a estratégia do projeto `PCM-Solicitacao-OS`:
`TotvsAssetsService`, `TotvsAssetQueries` e `webroot/js/request-form.js`.

O campo único pesquisa trechos de código ou nome. As sugestões são limitadas a
20 resultados, com atraso de 250 ms, cancelamento de requisições anteriores,
proteção contra respostas atrasadas, teclado, seleção por clique e mensagem de
ausência de resultados. Usa texto escapado no DOM e consultas parametrizadas.
As sugestões não excluem bens inativos, pois o histórico de O.S. também os inclui.

Selecionar grava código e filial separadamente do rótulo. Editar o texto limpa
essa identidade; sem seleção, aplica-se busca por trecho. A filial específica da
seleção não substitui permanentemente um filtro original de filial. Cadastros
compartilhados são associados às filiais de suas O.S., respeitando a precedência
do cadastro local. Links legados com `bem`/`nome_bem` continuam aceitos.

O filtro persiste na paginação e nas exportações de O.S. e apontamentos. Nos
drill-downs, um filtro adicional de equipamento é aplicado antes da paginação,
sem mudar as regras existentes de Safra/Entressafra ou os repositórios usados
pelos dashboards. O endpoint GET `/pcm/ordens/equipamentos` exige autenticação
normal e respeita a restrição já existente do perfil TV.

## Validação

- Suíte PHP: 83 testes e 2.156 asserções aprovados; teste adicional de quantidade
  ausente conferido depois da suíte. Suíte JavaScript: 7 testes aprovados.
- Testes automatizados de prioridade, ausência de descrição, binário inválido,
  deduplicação, contexto, filial, limites, SQL permitido e preservação dos valores.
- Cenário com descrição de apontamento sem descrição original testado com dados
  controlados; a amostra real de 1.000 O.S. não apresentou esse caso.
- Tarefas acessíveis não puderam ser testadas: liberação da TI é necessária.
- O.S. reais `006452` (ROTA 5B), `006478` e `006436`, filial `01`: listagem e
  detalhe concordam em `VERIFICAR TEMPERATURA`, de `ST4010.T4_DESCRIC`.
- O.S. `006478`: 06:10–07:15, `1.08 H` → `1h 5min (1,08 h)`.
- O.S. `006436`: 07:00–09:00, `2 H` → `2h (2,00 h)`.
- Amostra real: `005424/01` com descrição original, `006372/01` usando descrição
  detalhada do serviço e `006341/01` sem descrição disponível.
- Comparação de 1.000 O.S.: somente `descricao` muda; todos os outros campos e a
  ordem permanecem iguais. Leitura e enriquecimento em aproximadamente 0,4 s no
  ambiente local, sem garantia de desempenho para outros ambientes.
- Código completo `PREVMEC FAB 5B`, nome parcial `ROTA 5B`, nome `BOMBA`, código
  parcial `FAB 80` e termo inexistente conferidos na conexão somente leitura.
- Páginas 1 e 2 e dois XLSX reais gerados com o equipamento `PREVMEC FAB 01`,
  filial `01`; os arquivos foram reabertos e o código conferido em cada linha.
- Busca por trecho `ROTA` nos drill-downs de Safra aberta e backlog conferida.
- Testes JavaScript cobrem seleção por teclado, limpeza da seleção, ausência de
  resultados, falhas, sessão expirada, respostas atrasadas, texto seguro e horas.

Artefatos locais de conferência (não versionados):
`tmp/validacao-melhorias-ordens.json`, `tmp/validacao-ordens-orders.xlsx` e
`tmp/validacao-ordens-entries.xlsx`. Os XLSX são exportações comuns dessas telas;
o Plano de Ação Excel não foi alterado nesta tarefa.

## Arquivos desta alteração

- Rotas/acesso: `config/routes.php`, `src/Controller/AppController.php`,
  `src/Controller/PcmController.php` (ação de sugestões).
- Consulta e fontes: `src/Service/Protheus/OrderDescriptionQueries.php`,
  `OrderDescriptions.php`, `EquipmentLookupQueries.php`, `EquipmentFilter.php`,
  `ProtheusQueries.php`, `ProtheusRepository.php`, `OrderListingService.php` e
  `OrderEntryExportService.php`.
- Apresentação: `src/Service/Protheus/Presentation/LaborDuration.php`,
  `OrderSupplement.php`, `OrderSupplementMapper.php`, `OrderDetailPresenter.php`,
  `templates/Pcm/orders.php`, `webroot/js/pcm-protheus-order.js` e
  `webroot/js/pcm-equipment-autocomplete.js`.
- Identificação dos filtros exportados: `src/Service/OrderStreamingExport.php`.
- Testes: `tests/TestCase/Service/Protheus/OrderEnhancementsTest.php`,
  `OrderListingTest.php`, `tests/TestCase/Controller/ProtheusOrderAccessTest.php`,
  `tests/js/pcm-protheus-order.test.cjs` e `pcm-equipment-autocomplete.test.cjs`.
- Documentação: este arquivo.

As alterações anteriores do Plano de Ação presentes no workspace foram
preservadas. Não houve mudanças de banco, migrations, outros projetos,
indicadores, dashboards ou regras do Modo TV.
