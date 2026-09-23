-- Diagnóstico manual SOMENTE SELECT. Não atribui significado funcional aos serviços.
-- 1. Distribuição dos serviços das corretivas, por filial e situação/término brutos.
SELECT j.TJ_FILIAL, j.TJ_SERVICO, j.TJ_SITUACA, j.TJ_TERMINO, COUNT_BIG(*) AS quantidade
FROM dbo.STJ010 j
WHERE j.D_E_L_E_T_ <> '*' AND j.TJ_TIPO = 'COR'
GROUP BY j.TJ_FILIAL, j.TJ_SERVICO, j.TJ_SITUACA, j.TJ_TERMINO
ORDER BY j.TJ_FILIAL, quantidade DESC, j.TJ_SERVICO, j.TJ_SITUACA, j.TJ_TERMINO;

-- 2. Cadastros relacionados: filial local ou compartilhada; sem multiplicar contagens.
SELECT s.T4_FILIAL, s.T4_SERVICO, s.T4_NOME, s.T4_TIPOMAN
FROM dbo.ST4010 s
WHERE s.D_E_L_E_T_ <> '*' AND EXISTS (
    SELECT 1 FROM dbo.STJ010 j
    WHERE j.D_E_L_E_T_ <> '*' AND j.TJ_TIPO = 'COR'
      AND j.TJ_SERVICO = s.T4_SERVICO
      AND (s.T4_FILIAL = j.TJ_FILIAL OR s.T4_FILIAL = '')
)
ORDER BY s.T4_FILIAL, s.T4_SERVICO;

-- 3. Campos reais para investigar no SX3, sem presumir um discriminador.
SELECT s.name AS schema_name, t.name AS table_name, c.name AS column_name
FROM sys.tables t
INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
INNER JOIN sys.columns c ON c.object_id = t.object_id
WHERE t.name IN ('STJ010', 'ST4010')
ORDER BY t.name, c.column_id;
