# Revisão de auditoria — 17/11/2026

**Complementada pela [homologação final](homologacao-pcm-2026-11-17.md):** o recorte por início planejado descrito abaixo foi substituído por Real. Fim nas concluídas e origem nas abertas; a pesquisa de emitidas mantém origem. As consultas novas foram posteriormente executadas por SELECT no SQL Server. Os resultados finais e as pendências atualizadas constam naquele documento.

Alterações locais, sem deploy e sem modificar Ubuntu ou dados do Protheus.

## Regras aplicadas

- `PcmServiceClassifier`: ENTRESSAFRA no Nome Serviço prevalece sobre código, filial e Tipo Manut. Demais serviços permanecem na Safra; as subclasses de corretiva continuam disponíveis.
- Orientação complementar do usuário: **todas as O.S. abertas de Entressafra fazem parte da carteira ativa, inclusive 24-25 e registros sem início planejado**. O corte anterior de 01/01/2026 deixa de excluir essas O.S. A Safra conserva sua regra anterior.
- Abertas no Protheus: situação L e término N. Fechadas: L e S. Cancelamento C não entra em nenhum dos dois indicadores. Serviço Executado e simples liberação não determinam conclusão.
- Não há calendário agrícola oficial informado. Sem intervalo, abertas representam a carteira ativa; fechadas dos cartões sazonais mostram “—”. Concluídas históricas continuam consultáveis. Com início/fim explícitos, ambos os status são filtrados por início planejado, incluindo detalhamentos, contagens, paginação e exportações vinculadas.
- Áreas seguem exclusivamente o código de Área Manut. (`TJ_CODAREA`), usando o mapa central. ELETRI/INSTRU compõem Elétrica/Instrumentação. Código e área originais são conservados; rótulos antigos gravados localmente não substituem o mapa oficial.

## Divergências corrigidas

1. Lista fixa de códigos e precedência de corretiva classificavam incorretamente Entressafra.
2. TV separava Instrumentação; nomes de algumas áreas divergiam do mapa oficial.
3. Datas filtravam listagens locais, mas não seus indicadores. O dashboard geral também não tinha intervalo próprio; fechadas históricas apareciam nos cartões sem identificar um período.
4. O corte de abertas excluía Entressafra antiga, contrariando a orientação complementar.
5. Canceladas eram excluídas também das consultas de detalhes. A pesquisa agora oferece “Canceladas — histórico”, com contagem e acesso aos detalhes/apontamentos, mantendo indicadores operacionais protegidos.
6. Dados vazios, áreas desconhecidas, TESTE e datas futuras não tinham sinalização uniforme nas listagens revisadas. Alertas são apenas informativos e não reclassificam nem corrigem a origem.
7. Uma contagem ausente podia ser apresentada como zero; agora aparece indisponível.

As proteções existentes de identidade por filial/O.S., agregação antes dos cadastros, validação de cadastros ambíguos e lista fechada de SELECT foram mantidas. Em caso de identidade duplicada/ambígua, o resultado é recusado em vez de somar a mesma O.S. novamente. A exceção de Entressafra usa EXISTS, sem multiplicar linhas por JOIN.

## Conferência do CSV fornecido

Executável reproduzível, sem carregar conexão com banco:

```powershell
php bin/audit-stj.php 'c:/Users/maria.goncalves/Desktop/sclxtom0.csv' 2026-11-17
```

| Verificação histórica | Encontrado |
| --- | ---: |
| O.S. únicas por filial/número | 7.388 |
| Nome Serviço contém ENTRESSAFRA | 2.335 |
| Demais serviços | 5.053 |
| Canceladas | 983 |
| Abertas não canceladas | 1.552 |
| Fechadas não canceladas | 4.853 |
| Identidades repetidas | 0 |
| Serviços TESTE com código ENTRES | 3 |
| Área vazia | 1 |
| Áreas desconhecidas | 0 |
| Origem posterior a 17/11/2026 | 15 |
| Real. Fim posterior a 17/11/2026 | 3 |

As 15 O.S. com origem futura também têm planejamento futuro. Ao verificar todos os campos de data, são **18 O.S. distintas** com alguma data futura: as 15 da referência mais 2781, 2951 e 4264 em Real. Fim. Planejamento futuro exige conferência; não significa automaticamente erro.

TESTE: 5520, 5521 e 5522, todas canceladas. A área vazia pertence à cancelada 1272. O script informa linha do CSV, número, filial, serviço e área originais para rastreabilidade e separa a conferência por ano civil de planejamento, sem tratar ano civil como calendário agrícola.

Os números acima são resultados da leitura, não constantes do sistema. O CSV já contém números sem zeros à esquerda; não se inventou largura para reconstruí-los. Consultas ao Protheus e links conservam o número textual original, inclusive seus zeros.

## Validação e limites

Testes automatizados cobrem classificação, mapa de áreas, carteira aberta de Entressafra antiga/sem data, cancelamento, períodos, filtros combinados, igualdade entre listagens/indicadores, contagens únicas, paginação, exportação, detalhes, Serviço Executado, descrições de rotas, checklist de segurança e acesso da TV. Sintaxe PHP/JavaScript também verificada.

Resultado final: **205 testes e 5.577 asserções passaram** — 138 testes em `tests/TestCase/Service/Protheus` e 67 no conjunto complementar de importação, snapshots, apresentação, exportações, execução e controladores. Relatórios JUnit locais: `tmp/audit-protheus.xml` e `tmp/audit-complement.xml`. PHP lint: 37 arquivos sem erros; os dois JavaScripts alterados passaram em `node --check`; `git diff --check` sem erros.

Não foi executada homologação das consultas novas contra um SQL Server real nem validação visual em uma TV física. A suíte completa foi iniciada e interrompida por duração; os conjuntos direcionados e de regressão estão identificados no resumo da entrega. Há um aviso pré-existente do PHP local sobre OpenSSL carregado duas vezes.

## Decisões humanas restantes

- Definir o recorte das **concluídas** quando se desejar um indicador agrícola do período. As abertas de Entressafra já seguem a decisão recebida e não dependem disso.
- Validar na origem os três TESTE, a área vazia e as datas futuras, especialmente os três Real. Fim. Nenhum desses registros foi alterado.
- Homologar a aplicação revisada no ambiente apropriado antes de uma eventual publicação, que não foi executada nesta tarefa.
