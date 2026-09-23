# Complementos Protheus no detalhe da OS

A rota existente `/pcm/os/{id}` preserva os dados PCM e a auditoria de snapshots.
O novo elemento carrega os complementos depois da página, sem condicionar a
renderização original ao SQL Server. Não há gravações no MariaDB/Protheus,
migrations, mudanças de CSV, indicadores, regras de status ou apresentação TV.

## Fluxo e permissões

- `GET /pcm/os/{id}/protheus`: consulta uma OS por número e filial do snapshot e
  a primeira página (10 OS) do seu equipamento no Protheus. Não executa health
  antes das consultas e não carrega detalhes de todas as OS do histórico.
- `?part=history&page=2`: verifica apenas a identidade da OS de contexto (SELECT
  de número/filial/bem) e consulta a página solicitada, sem recursos dessa OS.
- `?part=detail&os=004368`: abre em diálogo o detalhe Protheus da OS selecionada,
  conferindo a mesma filial e o mesmo bem da OS de contexto. Funciona inclusive
  para OS histórica sem snapshot local, sem criar registro local ou mudar os dados
  da OS PCM que está aberta. O diálogo identifica número, filial e fonte.

A nova ação usa o mesmo AppController/Authentication e valida o snapshot atual
com CurrentSnapshotService::order(). Não aceita filial ou bem arbitrário pela URL.
Sessões ausentes continuam bloqueadas; TV continua sem acesso ao detalhe. Respostas
são `no-store`. Após as verificações locais, a sessão é liberada antes de consultar
o SQL Server, evitando bloquear outras requisições do usuário.

## Apresentação e mapeamento confirmado

OrderSupplementMapper e OrderDetailPresenter existentes foram ampliados. A camada
web recebe somente campos selecionados, nunca SQL, objetos de exceção, varbinary,
payload completo ou credenciais. JavaScript usa textContent, sem HTML vindo do banco.

- Equipamento/nome: TJ_CODBEM e T9_NOME; serviço/nome: TJ_SERVICO e T4_NOME.
- Descrição: texto que o repositório já converte de TJ_OBSERVA.
- Área/centro de custo: TJ_CODAREA/TJ_CCUSTO, como códigos.
- Datas/horas de manutenção: TJ_DTMPINI/HOMPINI, TJ_DTMPFIM/HOMPFIM,
  TJ_DTMRINI/HOMRINI, TJ_DTMRFIM/HOMRFIM. Datas gerais PP/PR ficam em bloco expansível.
- Mão de obra M: T1_NOME; TL_CODIGO, TL_DTINICI, TL_HOINICI, TL_DTFIM, TL_HOFIM,
  TL_QUANTID e TL_UNIDADE. Exibe início e fim com suas datas, inclusive virada de dia.
- Materiais P: TL_CODIGO, B1_DESC, TL_QUANTID, TL_UNIDADE, TL_DTINICI/TL_HOINICI.

Essas chaves foram confirmadas pelo usuário com os dados reais. Não há fallback
automático para B1_UM: unidade ausente no apontamento permanece ausente. Quantidades
não são somadas nem calculadas a partir do intervalo; a unidade original fica
visível. Os múltiplos registros são preservados, inclusive do mesmo profissional.
Datas/horas inválidas e campos ausentes exibem travessão, sem datas fictícias.
As strings de data são formatadas sem conversão de fuso horário.

E/T não são classificados. TJ_TIPO não é traduzido nem utilizado para classificar
o serviço; TJ_USUAINI/TJ_USUAFIM não são apresentados. Situação e término do histórico
aparecem como códigos originais, sem passar pela regra temporária do PCM. Os dados
locais exibidos anteriormente permanecem intactos.

O detalhe busca cadastros pela filial da OS, com fallback somente para filial vazia
(cadastro compartilhado), como no histórico. Não busca outra filial não vazia.
Duplicidade continua causando fallback seguro, em vez de escolher um cadastro
arbitrário. Confirmar essa regra de compartilhamento para ST1/SB1 no ambiente real.
Cadastros repetidos dentro de uma OS são consultados uma vez por código; os
apontamentos continuam todos presentes. Todas as consultas seguem na allowlist.

## Falhas e limites

OrderProtheusService captura falhas de conexão, consulta, mapeamento e timeout.
A única mensagem de indisponibilidade é:
“Detalhes do Protheus temporariamente indisponíveis.”
Falha só do histórico preserva o detalhe complementar já obtido. OS ausente tem
mensagem de ausência distinta; lista vazia não é tratada como indisponibilidade.
Não há propagação da mensagem de exceção, nem mesmo em DEBUG=true.

O navegador cancela a espera após 15 s. No servidor, o repositório web deixa de
iniciar consultas após um orçamento de 8 s; cada consulta tem timeout PDO de 5 s.
Uma consulta já iniciada pode terminar depois do orçamento, portanto ele não é um
limite exato de tempo total. A abertura da conexão mantém o loginTimeout existente.
O driver ganhou apenas configuração de timeout via PDO::setAttribute: nenhuma
proteção de escrita foi removida e nenhum SET SQL foi acrescentado. O CLI mantém
seu comportamento e diagnóstico sanitizado. Referência:
[Microsoft — PDO::setAttribute / QUERY_TIMEOUT](https://learn.microsoft.com/en-us/sql/connect/php/pdo-setattribute?view=sql-server-ver17).

## Testes isolados (sem bootstrap da aplicação/migrations)

```powershell
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php tests/TestCase/Service/Protheus/OrderProtheusServiceTest.php tests/TestCase/Service/Protheus/OrderPresentationTest.php tests/TestCase/Service/Protheus/ProtheusIntegrationTest.php tests/TestCase/Controller/ProtheusOrderAccessTest.php
node --test tests/js/pcm-protheus-order.test.cjs
```

Usam mocks de repositório/conexão, usuários em memória e DOM simulado. Nenhuma
conexão real, escrita no banco ou bootstrap de fixtures/migrations é executado.

## Validação manual na empresa

1. Abrir o detalhe PCM da OS 004368 a partir da lista atual: conferir os dados
   locais intactos, MEL 80 115 / MOTOR ROSCA RO-02 - SILO 01, ELEPRE / PREVENTIVA
   ELETRICA, descrição TROCA DOS ROLAMENTOS DO MOTOR e materiais 002075/000110,
   quantidades/unidades/datas conforme o JSON real. Não deve aparecer classificação
   nova “Corretiva” derivada de TJ_TIPO=COR.
2. Abrir 004893: conferir FTR 30 001 / FILTRO ROTATIVO 1, COROPE, descrição LAVAR
   TODOS 3 FILTRO COM SODA e profissional 008382 / DAMIAO GONCALVES, 1 H,
   18/08/2026 09:30–10:30. Conferir todos os apontamentos, sem somas inferidas.
3. No histórico de MEL 80 115, conferir referência 18/08/2026 para 004368, ordem
   decrescente, “Ver mais”/“Anterior” e diálogo de cada OS (inclusive sem snapshot).
   Fechar o diálogo deve manter a OS PCM original visível e inalterada.
4. Conferir temas claro/escuro, tabelas em tela estreita, teclado/Escape no diálogo,
   campos ausentes e textos com acentos/quebras de linha.
5. Simular indisponibilidade de rede em ambiente controlado: página PCM deve abrir
   normalmente e o complemento mostrar somente a mensagem pública. No navegador,
   conferir resposta JSON sem SQLSTATE, host, senha ou stack trace.
6. Conferir login expirado e perfil TV: nenhum acesso ao novo endpoint. Não usar
   credenciais administrativas no datasource Protheus.
