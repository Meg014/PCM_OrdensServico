/* Etapas da O.S. — executar primeiro. Somente metadados/SELECT.
   Um OBJECT_ID nulo com permissão 0 pode significar falta de visibilidade.
   Não concluir que a tabela inexiste e não procurar outra empresa como atalho. */
SELECT candidate.object_name, OBJECT_ID(candidate.object_name) AS objeto_visivel,
    HAS_PERMS_BY_NAME(candidate.object_name, 'OBJECT', 'SELECT') AS pode_ler,
    HAS_PERMS_BY_NAME(candidate.object_name, 'OBJECT', 'VIEW DEFINITION') AS pode_ver_definicao
FROM (VALUES ('dbo.ST5010'), ('dbo.STH010'), ('dbo.STQ010'), ('dbo.STX010'),
    ('dbo.STG010'), ('dbo.STN010'), ('dbo.TPA010'), ('dbo.TT9010'),
    ('dbo.STJ010'), ('dbo.STL010')) candidate(object_name);

/* Confirmar nomes físicos, tipo do memo e TODAS as chaves/sequências na instalação. */
SELECT SCHEMA_NAME(o.schema_id) AS esquema, o.name AS objeto, o.type_desc,
    c.column_id, c.name AS coluna, ty.name AS tipo, c.max_length,
    HAS_PERMS_BY_NAME(QUOTENAME(SCHEMA_NAME(o.schema_id)) + '.' + QUOTENAME(o.name), 'OBJECT', 'SELECT') AS pode_ler
FROM sys.objects o
LEFT JOIN sys.columns c ON c.object_id = o.object_id
LEFT JOIN sys.types ty ON ty.user_type_id = c.user_type_id
WHERE o.is_ms_shipped = 0 AND (o.name IN ('ST5010', 'STH010', 'STQ010', 'STX010',
    'STG010', 'STN010', 'TPA010', 'TT9010', 'STJ010', 'STL010') OR o.type = 'V')
ORDER BY esquema, objeto, c.column_id;

/* Índices reais: ordem das colunas/unicidade. Não assumir que O.S. + etapa é chave. */
SELECT SCHEMA_NAME(o.schema_id) AS esquema, o.name AS objeto,
    i.name AS indice, i.is_unique, i.is_primary_key,
    ic.key_ordinal, ic.is_included_column, c.name AS coluna
FROM sys.objects o
JOIN sys.indexes i ON i.object_id = o.object_id
JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
WHERE o.name IN ('ST5010', 'STH010', 'STQ010', 'STX010', 'STJ010', 'STL010')
ORDER BY esquema, objeto, indice, ic.is_included_column, ic.key_ordinal, ic.index_column_id;

/* Dicionário, caso esteja no SQL e visível. A TI deve validar SX2/SX3/SIX no ambiente real. */
SELECT SCHEMA_NAME(o.schema_id) AS esquema, o.name AS objeto, c.name AS coluna
FROM sys.objects o JOIN sys.columns c ON c.object_id = o.object_id
WHERE o.is_ms_shipped = 0 AND
    (o.name LIKE 'SX2%' OR o.name LIKE 'SX3%' OR o.name LIKE 'SIX%'
     OR c.name IN ('X2_CHAVE', 'X2_ARQUIVO', 'X3_CAMPO', 'X3_ARQUIVO', 'INDICE'))
ORDER BY esquema, objeto, c.column_id;
