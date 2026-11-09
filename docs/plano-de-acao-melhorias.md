# Plano de Ação — melhorias locais de 09/11/2026

Atualização do layout: o download utiliza o [modelo aprovado](plano-de-acao-modelo-aprovado.md), somente com a aba principal, tarefas em L, execução em M e status em N. As posições M/N e referências às outras abas abaixo descrevem as etapas anteriores.

Complemento posterior: a investigação de `TJ_OBSERVA`, a origem atual da coluna H, a limpeza Excel e os SQLs para a TI estão em [Origem da descrição e do Serviço Executado](plano-de-acao-origem-observacoes.md). A origem exclusiva da execução e a versão da abertura continuam sem confirmação; o checklist não é usado como evidência de encerramento.

Sem deploy, acesso ao Ubuntu, migrations ou escrita no TOTVS. A conexão continua usando `ProtheusReadOnly`, com consultas fixas permitidas e parâmetros vinculados.

## Download e concorrência

O formulário mantém o endpoint GET existente, com melhoria progressiva via `pcm-action-plan.js`. Ao enviar, bloqueia imediatamente o botão, mostra spinner e “Gerando relatório...”. O bloqueio dura até receber o Blob inteiro ou ocorrer um erro; não depende de timeout para liberar a interface. Os filtros não são apagados. Somente depois da recepção completa o navegador recebe o arquivo para salvar. Não é possível detectar quando o usuário termina de gravar o arquivo em disco.

Um bloqueio `flock` não bloqueante por usuário, em `TMP/pcm-action-plan-{hash}.lock`, também impede duas gerações simultâneas no servidor da mesma instalação. Conflitos retornam HTTP 409 e uma mensagem amigável. Exceções e erros de validação liberam o bloqueio. Não remova o arquivo de lock durante uma geração. A implementação contempla a instalação atual em um servidor; múltiplas instâncias precisariam compartilhar esse lock.

Falhas de conexão/geração, sessão expirada, respostas não Excel e arquivos vazios não são tratados como downloads bem-sucedidos. A limpeza posterior do URL do Blob tem um pequeno atraso apenas para permitir que o navegador consuma o arquivo; esse atraso não controla o bloqueio do botão.

## Desempenho

Não havia N+1 de consultas PHP por O.S.: o relatório já fazia uma consulta única. Foram preservados esse contrato e todos os filtros. A consulta agora projeta os campos necessários do cabeçalho e filtra o conjunto de datas/unidade antes do enriquecimento com cadastros, tarefas e profissionais. O primeiro apontamento continua considerando todo o histórico, inclusive fora do intervalo; somente O.S. sem apontamento usam a abertura. Apontamento com data inválida não passa a usar abertura silenciosamente.

A maior otimização foi remover o carregamento integral das sete abas pelo PhpSpreadsheet e a criação de objetos de célula usados apenas como passagem para o XML. O gerador atualiza o OOXML original diretamente, mantém as demais partes do ZIP e utiliza as conversões existentes de datas/controles XML. O arquivo final continua sendo temporário e transmitido por stream.

Logs `info` de `ActionPlan` contêm `stage=query`, `stage=group` e `stage=excel`, tempos em milissegundos, contagens, bytes e preparo total. Falhas inesperadas registram somente a classe da exceção. Não são registrados credenciais, SQL com valores, nomes de pessoas ou textos das ordens.

As medições comparáveis usam setembro/2026, Todas, na mesma máquina: **802 linhas de consulta e 716 O.S.**, com os mesmos campos antigos conferidos integralmente. Relatórios locais de tempo: `tmp/action-plan-before-time.json`, `tmp/action-plan-after-time.json` e `tmp/action-plan-excel-benchmark.json`. O tempo de transferência da rede de produção não foi medido; os logs registram o tamanho do arquivo e o preparo, não o tempo até o usuário salvá-lo.

| Etapa | Antes | Depois |
|---|---:|---:|
| Consulta real, uma execução de cada versão | 0,825 s | 0,966 s, incluindo TT9010 |
| Agrupamento, uma execução | 0,0097 s | 0,0101 s |
| Excel final, mediana de 3 execuções alternadas por versão, mesmo conjunto | 5,124 s | 2,883 s |
| Tamanho do XLSX | 185.640 bytes | 193.256 bytes, incluindo M/N |

A redução medida na montagem do Excel final é **43,7%**, mesmo acrescentando as colunas de tarefas e Serviço Executado. A consulta ganhou o relacionamento de tarefas e não apresentou redução de tempo nessa amostra. Os valores não são promessa de tempo no servidor Ubuntu: são medições locais, sem controlar a infraestrutura da rede de produção. Os exemplos finais para conferir estão em `tmp/action-plan-before.xlsx` e `tmp/action-plan-after.xlsx`.

## Tarefas: relação comprovada

O cabeçalho `STJ010` é relacionado aos apontamentos ativos por `TJ_FILIAL = TL_FILIAL` e `TJ_ORDEM = TL_ORDEM`. Cada `TL_TAREFA` consulta `TT9010.TT9_TAREFA`, priorizando a filial exata e usando a filial compartilhada vazia somente se a exata não existir. Cadastros ambíguos impedem o relatório, evitando escolher descrição arbitrária. `ST4010` continua sendo o cadastro do serviço planejado, ligado por `TJ_SERVICO = T4_SERVICO`; não fornece uma tarefa nem o serviço efetivamente executado.

A nova coluna M, **TAREFAS APONTADAS**, preserva códigos e descrições distintos em linhas separadas dentro da mesma célula, sem multiplicar linhas da O.S. Repetições da mesma tarefa nos apontamentos não duplicam o texto. A coluna tem largura adequada e quebra de texto; números de O.S. continuam como texto com zeros à esquerda. Datas/status/cores, colunas antigas, imagem e demais abas permanecem preservados.

A consulta confirmou a tarefa `000117` em apontamentos de rotas, por exemplo O.S. `006341`, `006354`, `006360` e `006366`. O texto exportado é o cadastro real de `TT9_DESCRI`; não é copiado do exemplo do pedido nem de uma etapa. Em setembro, 294 das 716 O.S. possuem tarefa apontada.

**Limite conhecido:** `ST5010` continua sem permissão de SELECT. O sistema não infere o planejamento completo a partir de serviço, nome ou equipamento. Rotas sem tarefa registrada em STL ficam com M vazia. O planejamento completo depende da liberação da fonte pela TI e da comprovação de suas chaves; não foi tentado contornar a restrição.

## Serviço Executado — coluna preparada, sem fonte presumida

Por orientação explícita do usuário nesta etapa, a nova coluna N, **SERVIÇO EXECUTADO**, permanece **vazia**. Não usa `TL_OBSERVA`, `TJ_OBSERVA`, tarefa ou nome do serviço como substituto.

Foram consultados metadados acessíveis e os detalhes/apontamentos já implementados. `TJ_OBSERVA` e `TL_OBSERVA` são `varbinary`. Nos 2.432 registros da consulta de O.S. finalizadas analisados, não houve texto útil em `TL_OBSERVA`. `TJ_OBSERVA` contém textos mistos: itens planejados e, ocasionalmente, frases narrativas no passado; isso não comprova um campo exclusivo de fechamento. Não há base segura para rotular esse conteúdo como Serviço Executado.

O usuário está investigando a origem no TOTVS e informará tabela/campo/relacionamento e um exemplo preenchido. Somente depois dessa validação deverá ser implementada a alimentação da coluna, incluindo agregação de múltiplos registros. Não foi inventado um teste de correspondência real para uma fonte ainda desconhecida; o teste atual exige que a coluna exista e fique vazia.

## Fábrica: ajuste exclusivo deste relatório

O catálogo geral permanece intacto: `4101012` continua Usina e `5101006` continua Setores nos outros módulos. Na consulta atual há serviços de outras unidades nesses mesmos centros; por isso nenhum centro inteiro foi reclassificado.

O novo catálogo `resources/data/action-plan-factory-routes.php` contém **25 tuplas exatas**, confirmadas em STJ010 e ST9010, sempre na filial `01`:

| Equipamentos | Centro | Serviço |
|---|---|---|
| PREVMEC FAB 01–10 e PREVMEC FAB 5B | 4101012 | PREMEC |
| PREVLUB FAB 01–09 | 4101012 | PRELUB |
| PREVELE FAB 01–05 | 5101006 | PREELE |

Para essas tuplas, a unidade efetiva no Plano de Ação é Fábrica. Qualquer outro equipamento, filial ou serviço mantém o classificador anterior. Os centros de Recepção `3101001` e Extração `3101005` das rotas ROTA05–ROTA09 já pertenciam a Fábrica e não precisaram de alteração. As descrições dos equipamentos confirmam Preparação, Farelo, Extração, Recepção, silos e Lubrificação; não foram usados prefixos de texto como critério de seleção em produção.

Conferência real de 2026, pelo período de referência existente: **3.661 O.S. no total, sem duplicações**; Fábrica passa de **1.539 para 1.630** e Usina de **2.020 para 1.939**. As 91 mudanças são 81 provenientes de Usina e 10 de Setores. As consultas filtradas concordaram com um cálculo independente em PHP, usando as tuplas e o catálogo geral. Em setembro não há mudança de classificação; o conjunto usado no benchmark continua idêntico. Evidências: `tmp/action-plan-real-validation.json` e `tmp/action-plan-route-validation.json`.

## Arquivos da entrega

- `src/Controller/PcmController.php` — somente `exportActionPlan`: lock e medições.
- `src/Service/ActionPlanService.php` — tempos e agrupamento das tarefas.
- `src/Service/ActionPlanExcelReport.php` — escrita OOXML, M/N e preservação do modelo.
- `src/Service/Protheus/ActionPlanQuery.php` — consulta otimizada, tarefas e SELECT fixo de diagnóstico.
- `src/Service/Protheus/ActionPlanRouteScope.php` — filtro isolado por tuplas.
- `src/Service/Protheus/ProtheusQueries.php` — inclusão somente do SELECT fixo na allowlist.
- `resources/data/action-plan-factory-routes.php` — 25 tuplas auditadas.
- `templates/Pcm/action_plan.php` e `webroot/js/pcm-action-plan.js` — download assíncrono e estados.
- `tests/TestCase/Service/ActionPlanTest.php`, `tests/TestCase/Controller/ProtheusOrderAccessTest.php` e `tests/js/pcm-action-plan.test.cjs`.
- Este documento. Scripts/arquivos de evidência em `tmp/` não integram o deploy.

## Testes e implantação

Resultado final: **361 testes PHP, 6.474 asserções e 39 testes JavaScript aprovados**. A rodada específica do Plano de Ação também passou, com 20 testes e 488 asserções. Relatórios: `tmp/action-plan-final.log`, `tmp/action-plan-final.xml`, `tmp/action-plan-new-focused.log` e `tmp/action-plan-js.log`. A inspeção no Chrome está em `tmp/action-plan-browser.log` e a captura de celular em `tmp/action-plan-mobile.png`.

Testes PHP cobrem relações por filial/O.S., tarefas repetidas e múltiplas, cadastro ambíguo, coluna N vazia, identidade e layout do Excel, SQL somente leitura, filtros e bloqueio concorrente. Testes JavaScript cobrem bloqueio até receber o Blob, cliques repetidos, filtros mantidos, falhas, expiração da sessão, conflito e arquivo vazio. Chrome local também conferiu 1440 e 390 px, download simulado, erro e recuperação sem acesso ao endpoint real de exportação.

**Nenhuma migration nem variável de ambiente nova.** PHP web deve continuar com `TMP` gravável. A nova consulta exige SELECT em TT9010, confirmado na conexão local atual; não exige ST5010 e não altera permissões. Publique PHP, catálogo de rotas e JavaScript juntos quando decidir validar no Ubuntu. Não sobrescreva `.env`; não há mudança de SMTP, cron, Não Conformidades, outras telas ou dados TOTVS.
