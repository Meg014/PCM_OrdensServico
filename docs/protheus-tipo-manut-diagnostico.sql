-- Executar manualmente no banco Protheus. Somente SELECT; não é consulta da aplicação.
-- 1. Localizar o SX3 físico, sem presumir sufixo/empresa/schema ou sua presença no SQL.
SELECT s.name AS schema_name, t.name AS table_name
FROM sys.tables t INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
WHERE t.name LIKE 'SX3%'
ORDER BY s.name, t.name;

-- 2. Campos efetivamente existentes nos cadastros já utilizados e no SX3.
-- Enviar o resultado para montar a próxima consulta sem inventar nomes de campos.
SELECT s.name AS schema_name, t.name AS table_name, c.name AS column_name,
       ty.name AS sql_type, c.max_length
FROM sys.tables t
INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
INNER JOIN sys.columns c ON c.object_id = t.object_id
INNER JOIN sys.types ty ON ty.user_type_id = c.user_type_id
WHERE t.name IN ('STJ010', 'ST4010') OR t.name LIKE 'SX3%'
ORDER BY t.name, c.column_id;

-- 3. Amostra das OS conhecidas, sem assumir que TJ_TIPO seja Tipo Manut.
SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_TIPO, j.TJ_SERVICO,
       j.TJ_SITUACA, j.TJ_TERMINO, j.TJ_DTMPINI
FROM dbo.STJ010 j
WHERE j.D_E_L_E_T_ <> '*' AND j.TJ_ORDEM IN ('004368', '004893')
ORDER BY j.TJ_FILIAL, j.TJ_ORDEM, j.R_E_C_N_O_;

-- 4. Cadastros locais/compartilhados dos serviços conhecidos, sem associar outros campos.
SELECT s.T4_FILIAL, s.T4_SERVICO, s.T4_NOME
FROM dbo.ST4010 s
WHERE s.D_E_L_E_T_ <> '*' AND s.T4_SERVICO IN ('ELEPRE', 'COROPE')
ORDER BY s.T4_FILIAL, s.T4_SERVICO;
