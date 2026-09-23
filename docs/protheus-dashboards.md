# PCM Geral e Apresentação: primeira etapa direta

## Regra encontrada e bloqueio de equivalências

O `WorkOrderStatusResolver` versão 4 interpreta os textos do Excel:

- Situação normalizada `cancelada` ou `cancelado`: CANCELADA, com precedência.
- Término normalizado `sim`: FECHADA.
- Qualquer outro caso: EM ABERTO.

O escopo operacional atual (`WorkOrderSnapshotsTable`) inclui abertas com início
planejado de manutenção >= 01/01/2026 e todas as fechadas, mesmo sem data planejada.
Canceladas não entram nesse escopo. Total = abertas + fechadas. Eficiência =
fechadas / (abertas + fechadas) × 100; denominador zero produz 0 no legado.
O legado não separa “em andamento” de “não iniciada”.

Ainda falta confirmar com dados reais:

1. Valores de TJ_SITUACA que significam cancelamento, incluindo precedência sobre término.
2. Valores de TJ_TERMINO que equivalem ao texto “sim” do relatório, e tratamento dos demais códigos.
3. Regra de andamento/não iniciada: quais campos reais gerais ou de manutenção usar,
   como tratar datas inválidas e conflitos. Ter data real não foi assumido como status.
4. Correspondência do início planejado do relatório com TJ_DTMPINI para reproduzir
   o corte temporário, sem ampliá-lo ou removê-lo por suposição.
5. Classificação de tipos e serviços: TJ_TIPO=COR não foi traduzido, conforme o caso
   ELEPRE da OS 004368. Safra/entressafra e subclasses dependentes da classificação
   antiga também estão pendentes; não são recalculadas por suposição.

Por isso os seis indicadores solicitados permanecem **NULL / aguardando validação**.
Não são mostrados como zero, nem preenchidos com números Excel. Uma contagem separada,
**O.S. não excluídas no Protheus**, permite validar a fonte sem confundi-la com o total
operacional anterior. Esta etapa é parcial deliberadamente, até confirmação das regras.

## Rotas e consulta

- `/pcm`: PCM Geral direto; `/pcm/data`: atualização JSON.
- `/pcm/apresentacao`: apresentação direta; `/pcm/apresentacao/data`: atualização JSON.
- `/pcm/legado`, `/pcm/apresentacao/legado`: comparação Excel, identificada como legado.
- Setoriais, análises, importações, listagem e detalhes mantêm a implementação anterior.
  A migração setorial completa aguarda validação desta etapa.

`ProtheusDashboardService → ProtheusRepository::dashboard → ProtheusQueries::DASHBOARD`.
Uma única consulta SQL por carregamento e por atualização, independentemente do número
de áreas. Nenhuma consulta individual por OS, nenhum acesso a snapshots nessas telas.
Autenticação continua consultando os usuários PCM normalmente.

STJ010: D_E_L_E_T_, TJ_FILIAL + TJ_ORDEM para identidade; TJ_CODAREA, TJ_CODBEM,
TJ_SERVICO, TJ_CCUSTO, TJ_TIPO, TJ_SITUACA, TJ_TERMINO para filtros/agrupamentos.
COUNT_BIG, GROUPING SETS e ROW_NUMBER executam as agregações e rankings no SQL Server.
São no máximo 61 linhas: total + 10 maiores grupos de cada uma das seis dimensões.
Cada grupo mantém filial, inclusive equipamento/serviço, sem associações entre filiais.
Não há JOIN, SELECT *, descrição binária, materiais ou apontamentos. Não são consultados
nomes adicionais nessa etapa: os gráficos exibem códigos técnicos sem tradução.
Chave filial + OS duplicada provoca indisponibilidade segura, não contagem silenciosa.

Filtros exatos parametrizados: filial, area, bem, servico, centro, tipo, situacao,
termino. Vazio significa sem filtro; comparações VARCHAR preservam espaços finais
sem funções sobre a coluna filtrada. Todos respeitam D_E_L_E_T_ <> '*'.
OPTION (RECOMPILE) permite ao otimizador considerar os filtros efetivamente preenchidos;
não cria índices nem altera o servidor. Validar plano/latência no banco real: uma
agregação global precisa ler os registros elegíveis. Timeout web de consulta: 5s.

## Atualização e falha segura

O payload inicial já contém os agregados: o navegador não repete a consulta ao abrir.
A cada 300.000 ms, fetch faz uma requisição CakePHP com os mesmos filtros, sem reload,
sem agendador, loop PHP ou escrita SQL. Não sobrepõe requisições; aborta após 15s.
Todos os componentes só são substituídos após validar a resposta completa.
Falha, redirecionamento para login, timeout e resposta incompleta mantêm a última
contagem, gráficos e horário válidos e exibem aviso discreto. Na primeira falha não há
números inventados. HTTP 503 traz apenas payload sanitizado, sem SQLSTATE/credenciais.
Respostas usam no-store. “Dados atualizados em” é o instante de conclusão da consulta
no backend, não o horário de uma importação nem uma garantia de atualização no TOTVS.

Apresentação mantém opção de tela cheia e alterna os rankings a cada 15s. Os cartões
de classificação antiga não são reutilizados com valores brutos sob rótulos incorretos.
Perfil TV permanece limitado às rotas de apresentação e seu endpoint existente.

## Validação

Automatizados sem bootstrap de banco/migrations: ProtheusDashboardTest,
ProtheusOrderAccessTest e tests/js/pcm-protheus-dashboard.test.cjs.
Não houve conexão real, migrations ou escrita em nenhum banco.

No PC da empresa: abrir `/pcm?filial=01`, conferir total não excluído e grupos com SQL
real, filtrar bem `MEL 80 115`, comparar as OS na listagem direta, testar apresentação
e perfil TV, aguardar 5 minutos, simular indisponibilidade controlada e verificar que
números/horário válidos são preservados. Comparar legado observando os escopos diferentes.
Confirmar as regras acima antes de habilitar os indicadores operacionais e migrar setores.
