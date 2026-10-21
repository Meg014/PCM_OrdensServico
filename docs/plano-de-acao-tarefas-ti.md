# Coluna H — auditoria e solicitação à TI

## Implementado e pendente

Por confirmação do usuário, a prioridade ativa é: observação da O.S.
(`STJ010.TJ_OBSERVA`), primeira observação legível dos apontamentos ativos de todo
o histórico (`STL010.TL_OBSERVA`), nome do serviço (`ST4010.T4_NOME`). A consulta de
tarefas fica pendente de autorização e validação no banco. Não foram alteradas
permissões nem tentados caminhos alternativos de acesso.

## Solicitação para encaminhar ao administrador

Solicitamos conceder à conta da integração PCM (PROTHEUS_DB_USERNAME), na mesma
base SQL Server em que lê STJ010/STL010, exclusivamente SELECT nestes objetos:

| Objeto | Finalidade da investigação |
| --- | --- |
| dbo.TT9010 | Cadastro de tarefas genéricas: consultar descrição e chave de tarefa/filial |
| dbo.ST5010 | Tarefas da manutenção: verificar descrição e vínculo com bem, serviço, sequência e tarefa |

Os dois objetos retornaram erro SQL Server 229, SELECT negado. Os nomes lógicos
TT9 e ST5 e suas finalidades constam da documentação oficial abaixo; os campos
físicos, compartilhamento de filial e relacionamentos ainda serão conferidos
na instalação após a liberação. Não foi presumido um join de tarefa no código.

Não é necessário conceder escrita, alteração de estrutura, execução de procedures,
controle de permissões, db_owner ou db_datareader sobre a base inteira. SELECT
nesses objetos permite inspecionar também os metadados de suas colunas; não é
solicitado VIEW DEFINITION sobre toda a base. A TI pode avaliar views de leitura
restritas equivalentes, informando seus nomes e campos, em lugar das tabelas.

STG010 e STH010 também retornaram 229 durante o diagnóstico, mas representam
insumos e etapas da manutenção, respectivamente. Não são incluídas no pedido
inicial mínimo de descrição de tarefas. Se a análise das duas tabelas autorizadas
demonstrar necessidade de outro vínculo, será apresentada uma solicitação específica.

Fonte: [TOTVS — Conhecendo o aplicativo MNT NG](https://tdn.totvs.com/display/PROT/Conhecendo%2Bo%2Baplicativo%2BMNT%2BNG).

## Seis O.S. verificadas diretamente, sem assumir filial

Todas as buscas foram por número sem impor filial; os registros ativos encontrados
para cada uma estão abaixo. As descrições atuais selecionadas vêm de
ST4010.T4_NOME, cadastrado na filial 01. As tarefas não puderam ser verificadas:
não se afirma que não existam.

| O.S. | Filial | TJ_OBSERVA | Observação ativa de apontamento | Texto selecionado em H |
| --- | --- | --- | --- | --- |
| 006444 | 01 | Nula | Sem apontamento ativo | PREVENTIVA MECANICA |
| 006452 | 01 | Nula | Sem apontamento ativo | PREVENTIVA MECANICA |
| 006473 | 01 | Nula | Sem apontamento ativo | PREVENTIVA LUBRIFICACAO |
| 006479 | 01 | Nula | Sem texto; apontamento M com TL_TAREFA=000117 | PREVENTIVA MECANICA |
| 006436 | 01 | Nula | Sem texto; apontamento M com TL_TAREFA=000117 | PREVENTIVA MECANICA |
| 006478 | 01 | Nula | Sem texto; apontamento M com TL_TAREFA=000117 | PREVENTIVA MECANICA |

O código TL_TAREFA nos três apontamentos não autoriza inferir a descrição ou o
compartilhamento do cadastro de tarefas. Não foi usado como texto de H.

## Comparação e testes

Comparação antes/depois sobre todas as O.S. elegíveis (referência entre
1900-01-01 e 9999-12-31): 5510 O.S., 818 células H vazias antes, zero depois;
818 passaram a ter descrição. Nenhuma O.S. adicionada ou removida; todos os
campos além da descrição permaneceram iguais. Cinco textos que continham apenas
ponto/apóstrofo também passaram a usar serviço; uma descrição útil teve removido
um controle isolado, mantendo o texto específico.

Comparação dos dois XLSX completos: todas as partes do ZIP fora da primeira aba
permaneceram byte a byte iguais. O XML da primeira aba permaneceu igual após
normalizar exclusivamente os valores de H, confirmando preservação de status,
datas, mecânicos, filtros, dimensões e formatação. Os arquivos de conferência
ficam em tmp/action-plan-before-task-fallback.xlsx e
tmp/action-plan-after-service-fallback.xlsx.

14 testes e 310 asserções aprovados em ActionPlanTest/ProtheusOrderAccessTest:
prioridade O.S. > apontamento > serviço, primeiro apontamento útil, ausência total,
UTF-8 inválido, binário, espaços Unicode, controle isolado em texto útil,
filiais distintas e preservação da planilha/status. O nível de tarefa não foi
testado nem implementado porque depende da liberação da TI.
