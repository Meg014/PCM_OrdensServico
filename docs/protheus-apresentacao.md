# Preparação do detalhe da OS (integração ainda desativada)

Nada foi conectado ao controller, template, container ou rotas. Não existe chamada
automática ao Protheus. O detalhe atual e sua auditoria de snapshots permanecem
iguais. As novas classes são funções/objetos em memória, sem persistência ou rede.

## Contrato preparado

`Presentation/OrderDetailPresenter::present()` recebe o snapshot atual como array
e retorna duas seções independentes:

- `pcm`: número/filial, equipamento/nome, serviço/nome, tipo de manutenção, área,
  centro de custo, data de origem e as oito datas planejadas/reais já importadas.
  Objetos de data são preservados para o helper PcmTime; classificação e nomes
  continuam sendo os do PCM. A área pode usar o helper MaintenanceArea existente.
- `protheus`: estado, mensagem pública fixa, descrição e listas de mão de obra e
  materiais. Não substitui valores do snapshot, nem retorna registros SQL brutos.

`OrderSupplement` define os campos futuros de profissional, código, data, início,
fim e horas; produto, descrição, quantidade, unidade, data e hora de uso. Decimais
são strings; desconhecido é null, nunca zero. Apontamentos repetidos e múltiplos
profissionais/materiais são preservados, sem soma ou deduplicação.

`OrderSupplementMapper` aceita em memória o resultado do ProtheusRepository e
mapeia apenas os campos confirmados: identidade, descrição já convertida e códigos
de M/P. Nomes, datas, horas, quantidades e unidades ficam null até confirmar suas
colunas e formatos no banco da empresa. O DTO já pode receber esses valores
normalizados depois dessa confirmação, sem mudança no contrato da apresentação.
E/T não entram nas listas de mão de obra/materiais e não recebem classificação.

O padrão é `Availability::Disabled`, inclusive quando alguém fornece um DTO.
Somente `Available` explícito permite apresentar complementos, após conferir
número e filial contra o snapshot. Ausência, identidade divergente ou filial não
mapeada resultam em fallback. `Unavailable` e `NotFound` têm mensagens fixas, sem
exceções, SQL, host ou credenciais. Não há configuração de ativação nesta etapa.
Na futura integração, falhas do provedor devem ser traduzidas para esses estados;
nunca entregar uma exceção à apresentação. Dados em texto devem passar por `h()`
no futuro template; não há HTML sendo gerado agora.

## Histórico do equipamento

`EquipmentHistoryReaderInterface` prepara consulta por código exato do bem e filial,
com paginação limitada. É só um contrato, sem implementação SQL. A implementação
futura deverá ordenar no servidor por data de referência DESC, nulos ao final e
chave única de desempate, depois de validar quais campos representam essa ordem.
Não ordenar apenas uma página em PHP e não usar o número da OS como data.

`EquipmentHistoryPage` transporta essa página ordenada, estado e indicação de
continuação. Prepara links da rota atual `pcm-order` somente com um ID de snapshot
local previamente resolvido/autorizado. Número da OS não é ID de snapshot. OS que
não existe no detalhe atual fica sem link até criar/validar uma rota Protheus;
nenhuma rota fictícia foi adicionada. O histórico de equipamentos não se confunde
com a auditoria dos snapshots da mesma OS exibida atualmente.

## Revisão do repositório

Não foi necessário refatorar o comportamento do ProtheusRepository. `read()` já
centraliza execução parametrizada, tipos string, fechamento do cursor e remoção
de espaços finais; `one()` acrescenta a verificação de ambiguidade para cadastros.
A futura consulta paginada pode reutilizar `read()` dentro do mesmo repositório,
com novo SQL explicitamente incluído na allowlist ProtheusQueries. Um adaptador
poderá implementar EquipmentHistoryReaderInterface usando esse resultado e resolver
os IDs locais separadamente. Não chamar findOrder() para cada item do histórico:
isso carregaria desnecessariamente materiais e mão de obra de todas as OS.

## O que validar no PC da empresa antes de ativar

1. Driver/ODBC, TLS, conta somente SELECT e consultas reais das OS 004893 e 004368.
2. Filial TJ_FILIAL/TL_FILIAL, compartilhamento de cadastros, chaves duplicadas e
   correspondência entre número/filial do PCM e Protheus (inclusive filial vazia).
3. Colunas de nomes e descrição de cadastros; data, hora inicial/final e unidade
   das horas; quantidade/unidade/data/hora de material, com formatos e nulos.
   Não derivar quantidade de horas do intervalo sem validar a regra.
4. Data de referência do histórico, desempate único, paginação, alcance por filial
   e custo da consulta com RTRIM nos dados reais.
5. Identidade/autorização e navegação de OS sem snapshot local. Só então implementar
   o leitor do histórico e conectar a apresentação a uma ativação controlada.

## Validação offline e restrita

```powershell
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php tests/TestCase/Service/Protheus/OrderPresentationTest.php
```

Os exemplos dos testes existem apenas em memória. O bootstrap padrão do projeto
não é usado, pois executa migrations. Não executar protheus_health nesta etapa.
