# Revisão funcional PCM — setembro de 2026

## Regras aplicadas

O dashboard usa exclusivamente a última importação com status `success`, pela ordenação por ID já existente em `CurrentSnapshotService`. Falhas posteriores não substituem essa fonte. Não há soma de snapshots históricos.

- `Situação=Cancelado/Cancelada`: CANCELADA, com prioridade sobre Término.
- Não cancelada e `Término=Sim`: FECHADA.
- Não cancelada e Término diferente de Sim: EM ABERTO.
- Datas reais não determinam abertura/fechamento. `WorkOrderStatusResolver` v4 permanece válido e não foi alterado.
- Total operacional = abertas + fechadas. Canceladas ficam fora dos cards principais, gráficos, rankings e resumo operacional. Permanecem no banco, na listagem com filtro de status e na auditoria.
- Preventivas, Corretivas e Melhorias abertas usam exclusivamente `Tipo Manut.` PRE, COR e MEL.
- Os três novos cards mostram apenas abertas: Corretivas Emergenciais, Corretivas Programadas e Entressafra.

Os gráficos históricos continuam mostrando cada snapshot separadamente. Seus totais operacionais e o comparativo entre setores agora também excluem canceladas; contagens de canceladas e transições continuam disponíveis para auditoria. Não foram alterados registros históricos.

## Classificação centralizada de serviços

`PcmServiceClassifier` classifica os valores do próprio snapshot, sem modificar `services.pcm_category`, `raw_payload` ou o relatório original.

1. Código normalizado `COREME`: EMERGENCIAL.
2. Código normalizado `CORPRO`: PROGRAMADA.
3. Fallback por nome contendo a expressão `CORRETIVA EMERGENCIAL` ou a grafia TOTVS `CORRETIVA EMERGENGIAL`.
4. Fallback por nome contendo `CORRETIVA PROGRAMADA`.
5. Nome normalizado contendo `ENTRESSAFRA`: ENTRESSAFRA, independentemente do código da safra.
6. Demais casos: OUTROS.

Maiúsculas, minúsculas, acentuação e espaços repetidos são normalizados. Código estável tem prioridade quando o nome for conflitante. Para indicadores, resumo e filtros de snapshots, EMERGENCIAL e PROGRAMADA exigem também `Tipo Manut.=COR`; um serviço não transforma PRE/MEL em corretiva. Entressafra pode abranger diferentes tipos.

O resumo por serviço tem grupos exclusivos: Emergencial, Programada, Entressafra, Preventivas e Outros. Preventivas corresponde a PRE quando não classificado nos grupos anteriores. Assim, a soma das linhas coincide com abertas/fechadas do conjunto filtrado, sem contagem duplicada. Nos cards, tipo de manutenção e classificação de serviço são dimensões diferentes: uma COR de Entressafra aparece nos dois cards correspondentes, que não devem ser somados entre si.

## Consultas, setores e Centro de Custo

`SectorDashboardService` reutiliza a mesma importação para listagem, filtros e agregados. A tabela permanece paginada no servidor. Filtros disponíveis: Serviço, Nome Serviço, Tipo Manut., Área Manut., Centro de Custo, Status, Equipamento, classificação e pesquisa textual.

`/pcm/ordens` reutiliza a visão analítica para todas as áreas. Os cards gerais abrem essa listagem; cards do setor mantêm a rota do setor e aplicam status aberto mais a classificação escolhida. Os filtros ativos são preservados. Para consultar canceladas, selecione o status correspondente; os KPIs operacionais ficarão zerados nesse recorte.

As contagens são agregadas no banco por status, tipo e definição do serviço. A classificação Unicode é aplicada no backend sobre essas definições/contagens agrupadas, nunca sobre todas as OS no navegador. O filtro de classificação usa somente definições distintas de serviço e parâmetros vinculados. Não existe cálculo de regras funcionais em JavaScript.

Área Manut. continua sendo a fonte principal da área. Centro de Custo permanece armazenado, exibido e filtrável, inclusive em cada setor. Não foi criado vínculo Centro de Custo → Área.

**Proposta futura, não implementada:** uma tabela de mapeamento explícito, com centro de custo, área, período de vigência e autoria/aprovação da operação. O mapeamento deve ser homologado antes de uso; não deve sobrescrever a Área Manut. original nem os snapshots históricos. Nenhuma migration é necessária para a revisão atual.

## Datas no detalhe da OS

Todos os campos já existiam e foram mantidos:

| Bloco na tela | Campo TOTVS | Campo persistido |
|---|---|---|
| Planejamento / Registro | P. In. Man. + hora | `maintenance_planned_start` |
| Planejamento / Registro | P. Fim Man. + hora | `maintenance_planned_end` |
| Execução real | Real. Início + hora | `general_actual_start` |
| Execução real | Real. Fim + hora | `general_actual_end` |
| Dados adicionais TOTVS | R. In. Man. + hora | `maintenance_actual_start` |
| Dados adicionais TOTVS | R. Fim Man. + hora | `maintenance_actual_end` |

Valores vazios são apresentados como ausentes, sem gerar datas. O histórico do detalhe identifica mudanças de Real. Início/Fim. Os controles de qualidade já existentes sobre R. In./Fim Man. permanecem como auditoria desses campos adicionais; não determinam STATUS.

## Apresentação / TV

São exibidos os mesmos oito indicadores da visão geral e dos setores. A rotação de 15 segundos e a atualização de 30 segundos foram preservadas. A ordem conhecida dos setores foi mantida, e áreas futuras presentes no snapshot são adicionadas dinamicamente. O layout de paisagem foi ajustado para comportar as três linhas de cards.

## Arquivos desta revisão

- `src/Service/PcmServiceClassifier.php` (novo).
- `src/Service/PcmIndicatorService.php`.
- `src/Service/SectorDashboardService.php`.
- `src/Service/PcmPresentationService.php`.
- `src/Service/PcmHistoryService.php`.
- `src/Model/Table/WorkOrderSnapshotsTable.php`.
- `src/Controller/PcmController.php`.
- `config/routes.php`.
- `templates/Pcm/index.php`, `sector.php`, `order.php`, `presentation.php`.
- `templates/element/pcm_indicator_cards.php`, `pcm_service_cards.php` (novo).
- `webroot/js/pcm-presentation.js` e `webroot/css/pcm.css`.
- `tests/TestCase/Service/PcmOperationalRevisionTest.php` (novo).
- `tests/TestCase/Service/CurrentSnapshotServiceTest.php` e `PcmHistoryServiceTest.php`.
- `tests/TestCase/Controller/PcmControllerTest.php`.
- `tests/JavaScript/pcm-presentation.test.cjs` (novo).
- `README.md` e este documento.

Não foram alterados datasource, infraestrutura, importadores, `source_row_number`, migrations ou payloads nesta revisão.

## Comandos de validação e execução

Não é necessário executar migrations, reimportar relatórios ou modificar o banco. As regras de apresentação se aplicam aos snapshots já armazenados.

Para validar no PowerShell, na raiz, com as dependências de desenvolvimento instaladas:

```powershell
$env:DATABASE_TEST_URL = 'sqlite://127.0.0.1/' + ((Join-Path $PWD ('tmp/functional-test-' + [guid]::NewGuid().ToString('N') + '.sqlite')) -replace '\\', '/')
composer test
Remove-Item Env:DATABASE_TEST_URL
composer cs-check
node --check webroot/js/pcm-presentation.js
node tests/JavaScript/pcm-presentation.test.cjs
```

O banco de teste precisa ser exclusivo; o bootstrap de testes administra os dados dele. Não aponte `DATABASE_TEST_URL` para o banco operacional. Não defina essa variável no arquivo de produção.

Se o servidor local ainda não estiver iniciado:

```powershell
php bin/cake.php server -H localhost -p 8765
```

Se já estiver rodando, apenas recarregue `http://localhost:8765/pcm` com Ctrl+F5. Em produção, publique os arquivos e reinicie o processo PHP caso o OPcache não detecte alterações automaticamente.

## Checklist manual

- [ ] Visão Geral: oito cards; abertas/fechadas sem canceladas.
- [ ] PRE/COR/MEL abertas correspondem ao Tipo Manut., sem inferência pelo código de serviço.
- [ ] Clique nos três novos cards e confira classificação, status aberto e total da listagem.
- [ ] Setores: confira os mesmos cards, gráficos e rankings restritos à área selecionada.
- [ ] Analítico: combine Serviço, Nome Serviço, tipo, área, Centro de Custo, status e equipamento; teste paginação e Limpar.
- [ ] Resumo por serviço: soma de abertas/fechadas coincide com o conjunto operacional filtrado.
- [ ] Auditoria: canceladas aparecem na listagem e no detalhe, sem entrar nos KPIs.
- [ ] Detalhe: confira planejamento, Real. Início/Fim e R. In./Fim Man. em blocos separados; datas ausentes continuam ausentes.
- [ ] Histórico e payload bruto continuam disponíveis.
- [ ] TV: oito indicadores visíveis, atualização dos três novos números em cada rotação e inclusão de áreas futuras.
- [ ] Valide nomes ambíguos de serviço com a operação; códigos estáveis prevalecem, e nenhum Centro de Custo foi atribuído automaticamente a uma área.

## Resultado da validação nesta entrega

- Suíte completa: 88 testes, 434 assertions; 86 passaram. Os dois casos de `TotvsCsvReaderTest` dependem do arquivo ausente `E:\projetos\PCM\relatorios_teste\Relatorio_OS_2026-08-28.csv` (uma falha e um erro). Não foram ocultados nem substituídos por dados inventados.
- Novas regras, filtros, rotas, resumo, exclusão de canceladas, precedência de código, normalização, subclasses COR e áreas futuras passaram nos testes.
- Sintaxe aprovada nos 18 PHP da revisão; JavaScript validado com `node --check`.
- Teste JavaScript da TV aprovado: novos contadores e rotação de 15 segundos.
- PHPCS aprovado para os quatro serviços operacionais revisados e o novo teste funcional. O lint geral ainda aponta 70 erros e 55 avisos anteriores em 25 arquivos, incluindo trechos não alterados de arquivos desta revisão.
- Validação somente leitura no MariaDB real: cards, resumo, TV e listagens filtradas coincidem em oito recortes (geral e sete setores).
- HTTP 200 confirmado na Visão Geral, listagem geral filtrada, setor filtrado e endpoint da TV.
- Nenhuma migration ou importação foi executada no banco operacional nesta revisão. Nenhum dado persistente foi alterado.
