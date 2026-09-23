# Correção dos indicadores gerenciais

Esta especificação substitui os cards provisórios descritos em protheus-dashboards.md.
Não houve alteração das regras do importador, snapshots ou classificador legado.

| Indicador | Regra legada | Origem Protheus | Implementação |
|---|---|---|---|
| Safra abertas/fechadas | classificação diferente de ENTRESSAFRA | TJ_SERVICO + ST4010.T4_NOME | habilitada |
| Entressafra abertas/fechadas | classificação ENTRESSAFRA | TJ_SERVICO + ST4010.T4_NOME | habilitada |
| Preventivas/Corretivas/Melhorias | Tipo Manut. PRE/COR/MEL em abertas elegíveis | campo não confirmado | NULL, pendente |
| Corretivas Emergenciais | Tipo Manut. COR + COREME ou nome emergencial | serviço confirmado, tipo pendente | NULL, pendente |
| Corretivas Programadas | Tipo Manut. COR + CORPRO ou nome programada | serviço confirmado, tipo pendente | NULL, pendente |

Prova no código: TotvsHeaderValidator separa `Tipo O. S.` (coluna 5) de `Tipo Manut.`
(coluna 11); TotvsRowMapper persiste order_type e maintenance_type separadamente.
PcmIndicatorService calcula os tipos somente sobre abertas elegíveis. Não existe
nesse importador metadado que prove qual campo SQL alimenta Tipo Manut.
TJ_TIPO **não** foi usado como maintenance_type nem traduzido.

## Safra/Entressafra

Reutiliza PcmServiceClassifier::classify, com a mesma normalização de acentos,
maiúsculas e espaços. Prioridade: COREME, CORPRO, regex de nomes emergencial/programada,
depois nome contendo ENTRESSAFRA; demais são Safra. Não usa estação, calendário ou
datas para deduzir Safra. classifySnapshot só transforma EMERGENCIAL/PROGRAMADA em
OUTROS quando Tipo Manut. não é COR; ambos já pertencem a Safra. Portanto a partição
Safra/Entressafra é idêntica e não depende da equivalência de Tipo Manut.
Nome de serviço ausente/ambíguo em grupo elegível bloqueia o resultado em vez de
classificar silenciosamente como Safra.

Status confirmados pelo usuário no SX3: C/L/P e N/S.
Abertas: término N, situação diferente de C, preservando o corte legado de início
planejado de manutenção >= 01/01/2026 (TJ_DTMPINI, já utilizado pelo mapper validado).
Datas inválidas/ausentes não passam pelo corte. Fechadas S não têm corte de data.
Canceladas C/N não contam. Eventual C/S é conflito entre a regra candidata e a
precedência antiga: consulta fica indisponível até confirmação, sem inferir regra.
Códigos desconhecidos também bloqueiam contagens. Na distribuição informada:
4.254 S, 287 N não canceladas **antes do corte**, 809 C/N; não afirmar que as 287
passam pelo corte ou pertencem todas a Safra. Esses números não estão fixados no código.

## Consulta e interface

ProtheusQueries::MANAGEMENT usa STJ010 e ST4010. Uma consulta por carregamento/refresh.
Filtra e agrega no SQL Server por filial + área + serviço, com SUM/COUNT_BIG.
Aplica filial local/compartilhada, D_E_L_E_T_ <> '*', parâmetros vinculados e igualdade
VARCHAR com espaços finais. Nenhum STL010, N+1, SQL livre ou escrita. No máximo
2.000 grupos; o 2.001º detecta limite e impede publicar resultado parcial.
O PHP soma contagens agregadas, não percorre milhares de OS. O classificador não foi
reescrito em SQL para evitar divergências de regex/normalização do legado.
Reutiliza somente a constante do corte do legado; não consulta MariaDB/snapshots.

Os nove cards originais voltaram. Só os quatro seguros têm números. Nenhum card
Total operacional/Concluídas/Andamento/Canceladas/Não iniciadas/Eficiência permaneceu.
Contagem bruta fica recolhida em Informação técnica. Visão Gerencial alterna visão
geral e áreas a cada 15s, como antes, com nomes já definidos no PCM.
Áreas somam filiais, como o legado; filtro filial restringe essa soma.
Endpoints atuais, autenticação, logout TV, atualização 300s e preservação da última
visualização válida permanecem. Nenhum fallback silencioso para Excel.

## Investigação de Tipo Manut.

Candidatos **a investigar**, não equivalências comprovadas:

1. Campo próprio de tipo de manutenção na STJ010, distinto de Tipo O.S.
2. Campo de tipo/classificação na ST4010 (cadastro do serviço), ligado por
   TJ_SERVICO = T4_SERVICO, respeitando filial/compartilhamento.
3. Referência de STJ/ST4 para manutenção padrão, se indicada no SX3. A integração
   ainda não confirmou uma tabela/campo/chave dessa referência; nenhum JOIN foi criado.

Executar protheus-tipo-manut-diagnostico.sql, somente SELECT. Ele localiza SX3,
lista colunas reais e extrai as duas OS/serviços conhecidos. Depois consultar no SX3
os títulos/descrições, validações e relações dos campos candidatos. Se SX3 não estiver
no SQL Server, obter a definição pelo configurador TOTVS. Não supor SX3010.
Precisamos comparar o valor encontrado com a coluna Tipo Manut. do relatório real,
especialmente OS 004368 (serviço ELEPRE com TJ_TIPO COR), além de exemplos PRE/COR/MEL.
Metadados isolados não provam equivalência. Não deduzir tipo pelo nome do serviço.

## Validar no PC da empresa

Abrir /pcm e /pcm/apresentacao. Conferir as quatro somas, corte das abertas e
casos COREME/CORPRO/ENTRESSAFRA, filiais e rotação das áreas. Conferir os cinco
indicadores pendentes e o fallback após falha, aguardando o refresh de 5 minutos.
Testes dirigidos usam mocks e não executam o SQL no servidor real.
