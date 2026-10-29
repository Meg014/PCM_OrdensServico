# Melhorias de setores e descrições — 29/10/2026

## Etapas entregues

1. **Setores:** CALD → Caldeira, FAB → Fábrica, TRATA → Tratamento de Caldo.
   O menu apresenta uma única opção Elétrica e Instrumentação. ELETRI e INSTRU
   são agregados na consulta, mantendo os códigos de origem nas linhas. O link
   antigo INSTRU redireciona para ELETRI com os parâmetros GET preservados.
   CALDEI continua sendo Caldeiraria; não foi confundido com CALD.
2. **Filtros compactos:** botões Todas/Fábrica/Usina, autocomplete existente de
   `/pcm/ordens`, início e fim planejados em dd/mm/aaaa, Aplicar e Limpar.
   Datas brasileiras são normalizadas para ISO e validadas no servidor.
   As regras de unidade continuam exclusivamente em ProtheusUnit.
3. **Abrangência e persistência:** o período passa a atuar antes das agregações
   de Safra/Entressafra, indicadores, backlog e rankings, além da tabela e das
   exportações. As regras próprias de elegibilidade, Safra e idade do backlog
   foram preservadas. Os filtros de unidade e Bem também percorrem essas consultas.
   GET mantém os filtros na paginação, atualização automática, drilldowns e
   exportações. `return_sector` e `return_filters` preservam o retorno dos detalhes
   da O.S. e do equipamento. O botão Limpar aponta para a rota sem filtros.
4. **Descrição principal:** utiliza a observação original da O.S. e oculta apenas
   o sufixo completo reconhecido de cinco perguntas padronizadas. Aceita a
   variante real de 007042 com opções sim/não/não aplicável e referência a
   proteção/botões/sinalização. Texto ambíguo, perguntas incompletas ou atividades
   posteriores preservam o original. Mantém atividades legítimas sobre limpeza
   e segurança. Um bloco recolhível identifica o conteúdo original do TOTVS.
5. **Tarefas e etapas — entrega parcial:** catálogo TT9010 acessível, ligado
   somente a tarefas explicitamente registradas em STL010 para a filial/O.S.
   consultada. A origem informa plano e sequência; etapas sem descrição acessível
   mostram somente seu código identificado. Não há associação por serviço para
   inferir tarefas de outra O.S. ST5010 continua sem SELECT nesta conexão, por
   isso tarefas apenas planejadas e descrições detalhadas de etapas permanecem
   indisponíveis. A interface informa essa limitação discretamente.
6. **Mão de obra:** reutilizada a conversão já existente de TL_OBSERVA por registro.
   A coluna Descrição do apontamento e o texto Sem descrição registrada já
   existiam e foram preservados. As células agora preservam quebras de linha e
   acomodam palavras/textos longos. O formato de horas e os avisos de divergência
   existentes foram mantidos; não havia linha redundante a remover nesta versão.
7. **Validação:** testes automatizados de serviço, HTTP e verificações somente
   leitura na conexão atual, detalhados abaixo.

## Tabelas e campos

| Fonte | Uso |
| --- | --- |
| STJ010.TJ_FILIAL, TJ_ORDEM, TJ_CODAREA | Identidade e seleção dos setores originais |
| STJ010.TJ_CCUSTO | Unidade pela classificação centralizada |
| STJ010.TJ_CODBEM, TJ_DTMPINI | Filtro exato do Bem e início planejado |
| STJ010.TJ_OBSERVA | Descrição original, convertida por CONVERT(VARCHAR(MAX), ...) |
| STJ010.TJ_PLANO, TJ_SEQRELA | Campos confirmados nos metadados e disponíveis no detalhe bruto interno |
| ST9010.T9_FILIAL, T9_CODBEM, T9_NOME | Autocomplete existente e prioridade filial exata/compartilhada |
| STL010.TL_FILIAL, TL_ORDEM, R_E_C_N_O_ | Identidade dos registros da O.S. e ordenação existente |
| STL010.TL_OBSERVA | Descrição individual convertida de varbinary pela consulta existente |
| STL010.TL_TAREFA, TL_ETAPA, TL_PLANO, TL_SEQRELA | Vínculos explícitos de tarefas/etapas registradas |
| TT9010.TT9_FILIAL, TT9_TAREFA, TT9_DESCRI | Descrição de tarefa: filial exata tem prioridade sobre compartilhada; cadastro ambíguo não fornece descrição |
| ST4010.T4_DESCRIC | Descrição detalhada de serviço já existente, com origem identificada |
| ST1010.T1_FILIAL, T1_CODFUNC, T1_NOME | Profissionais, pela resolução existente |
| sys.objects, sys.columns, sys.types | Verificação de metadados e permissões, sem leitura dos dados negados |

`T4_NOME` permanece apenas como nome do serviço; não substitui descrição detalhada.
Nenhuma descrição de serviço ou apontamento substitui a descrição original ausente
na seção principal. As outras fontes aparecem em suas seções identificadas.
O catálogo visível não contém coluna separada chamada TAG. A pesquisa encontra
TAG quando armazenada no código ou nome do Bem; nenhum campo alternativo foi inferido.

## Resultados na conexão real

- Contagens independentes dos códigos originais, sem a transformação de união:
  ELETRI **603**, INSTRU **343**. Total unificado **946** O.S. operacionais.
- Exportação completa da carteira: **946** identidades filial/número distintas,
  sem duplicidades e sem limite visual de 20 linhas.
- Todas: **946**; Fábrica: **419**; Usina: **456**; Outros/Sem unidade: **71**.
  A soma das três classificações coincide com Todas.
- Fábrica + período planejado 01/10/2026–29/10/2026: **25** O.S.; total e KPIs
  idênticos entre tela e consulta de exportação. Todas as linhas no intervalo.
- Autocomplete `RED`: sugestões reais retornadas. Seleção por código e filial
  confirmada. Um equipamento sem O.S. no setor agora produz uma carteira vazia
  disponível; foi corrigida a ausência da linha de total zero na agregação SQL.
- Exportação de apontamentos com unidade/período: consulta disponível.
- 007042, filial 01: quatro atividades mantidas; apenas o checklist reconhecido
  ocultado na descrição principal; original preservado.
- 006341, filial 01: descrição original ausente. Não há apontamentos de mão de
  obra na consulta existente. Não foi inferida uma descrição pelo serviço/plano.
- 006955, filial 01: identidade do apontamento individual confirmada. A amostra
  tem TL_OBSERVA vazio, apresentando a mensagem de ausência. A consulta em lote
  das 946 O.S. unificadas não encontrou observações úteis de apontamentos para
  demonstrar um caso real preenchido; a projeção de textos individuais é coberta
  pelos testes existentes.
- Consulta direta de tarefas validada: 006955 possui tarefa 000111, descrição
  MANUTENCAO CORRETIVA, plano 000000, sequência 1. A etapa está vazia.
  007042 e 006341 não retornaram tarefas por esse vínculo. Não se inferiu
  uma tarefa do plano/serviço de outra O.S.
- Permissões atuais: TT9010=1, ST4010=1, ST5010=0. Somente as seis tabelas ST/TT
  visíveis foram identificadas nos metadados; não se contornou a restrição de ST5010.

Esses números representam a consulta durante a implementação; a carteira é dinâmica.
Não foram executadas alterações de dados ou estrutura no TOTVS. O driver e sua
lista de consultas permitidas continuam bloqueando SQL arbitrário e escrita.

## Testes e limitações

- Testes isolados de serviços Protheus: 109 testes, 2.754 asserções, aprovados.
- Teste HTTP de setores: 2 testes, 26 asserções, aprovados, incluindo os links
  dos cards, paginação, exportação e redirecionamento INSTRU.
- Testes de senha: 9 testes, 113 asserções, aprovados após carregar explicitamente
  as rotas no preparo isolado do teste. Não houve alteração na autenticação.
- Sintaxe PHP e `git diff --check`: aprovados nos arquivos alterados.
- Suíte completa `php vendor/phpunit/phpunit/phpunit`: **267 testes,
  4.349 asserções, todos aprovados**.
- Node.js não está disponível no ambiente; os testes JavaScript não foram executados.
  Não houve validação visual interativa em navegador.
- Faltam permissão e relacionamentos validados para planejamento ST5010 e fontes
  de descrição detalhada de etapas. A O.S. 006341 permanece sem essas informações.
  Casos reais de múltiplas tarefas/etapas não foram confirmados nesta conexão.

## Arquivos alterados

- `src/Controller/PcmController.php`
- `src/Model/Table/MaintenanceAreasTable.php`
- `src/Service/Protheus/OrderDescriptionQueries.php`
- `src/Service/Protheus/OrderDescriptions.php`
- `src/Service/Protheus/OrderEntryExportService.php`
- `src/Service/Protheus/Presentation/OrderDetailPresenter.php`
- `src/Service/Protheus/Presentation/OrderSupplementMapper.php`
- `src/Service/Protheus/ProtheusQueries.php`
- `src/Service/Protheus/ProtheusRepository.php`
- `src/Service/Protheus/ProtheusSectorQueries.php`
- `src/Service/Protheus/ProtheusSectorService.php`
- `src/Service/Protheus/SectorPresentationScope.php` (novo)
- `templates/Pcm/equipment.php`
- `templates/Pcm/protheus_order.php`
- `templates/Pcm/sector_protheus.php`
- `templates/element/protheus_order.php`
- `templates/element/protheus_sector_content.php`
- `templates/element/sector_return_link.php` (novo)
- `webroot/js/pcm-protheus-order.js`
- `tests/TestCase/Controller/PasswordProfileTest.php`
- `tests/TestCase/Controller/PcmControllerTest.php`
- `tests/TestCase/Service/OrderDateFiltersTest.php`
- `tests/TestCase/Service/Protheus/ProtheusDashboardTest.php`
- `tests/TestCase/Service/Protheus/ProtheusScopeTest.php`
- `tests/TestCase/Service/Protheus/ProtheusSectorTest.php`
- `tests/TestCase/Service/Protheus/SectorImprovementsTest.php` (novo)
- Este relatório.

Os scripts de conferência locais e resultados de metadados permanecem em `tmp/`,
fora do controle de versão. Nenhum arquivo do projeto PCM-Solicitacao-OS foi alterado.
