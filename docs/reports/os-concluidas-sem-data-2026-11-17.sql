-- SELECTs usados na investigação. Serviço Executado: consulta em lote existente de STQ010 por filial/O.S.
WITH missing AS (
    SELECT j.*, COUNT_BIG(*) OVER (PARTITION BY j.TJ_FILIAL, j.TJ_ORDEM) AS identity_count
    FROM dbo.STJ010 j
    WHERE j.D_E_L_E_T_ <> '*' AND j.TJ_SITUACA = 'L' AND j.TJ_TERMINO = 'S'
        AND TRY_CONVERT(date, NULLIF(j.TJ_DTPRFIM, ''), 112) IS NULL
)
SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_CODBEM, j.TJ_SERVICO, j.TJ_CODAREA, j.TJ_SITUACA, j.TJ_TERMINO,
    j.TJ_DTPRFIM, DATALENGTH(j.TJ_DTPRFIM) AS completion_bytes,
    CONVERT(varchar(128), CONVERT(varbinary(64), j.TJ_DTPRFIM), 2) AS completion_hex,
    CASE WHEN j.TJ_DTPRFIM IS NULL THEN 'NULO'
        WHEN LTRIM(RTRIM(j.TJ_DTPRFIM)) = '' THEN 'VAZIO'
        WHEN LTRIM(RTRIM(j.TJ_DTPRFIM)) = '00000000' THEN 'ZEROS'
        WHEN LEN(LTRIM(RTRIM(j.TJ_DTPRFIM))) <> 8 THEN 'FORMATO'
        ELSE 'DATA_INVALIDA' END AS completion_problem,
    j.TJ_DTORIGI, j.TJ_TIPOOS, j.TJ_TIPO, j.TJ_PLANO, j.TJ_DTPRINI, j.TJ_HOPRINI, j.TJ_DTMRINI, j.TJ_HOMRINI,
    j.TJ_DTMRFIM, j.TJ_HOMRFIM, j.TJ_DTMPINI, j.TJ_DTMPFIM,
    CONVERT(varchar(10), TRY_CONVERT(date, NULLIF(j.TJ_DTORIGI, ''), 112), 23) AS origin_date,
    CONVERT(varchar(10), TRY_CONVERT(date, NULLIF(j.TJ_DTPRINI, ''), 112), 23) AS real_start,
    CONVERT(varchar(10), TRY_CONVERT(date, NULLIF(j.TJ_DTMRINI, ''), 112), 23) AS maintenance_real_start,
    CONVERT(varchar(10), TRY_CONVERT(date, NULLIF(j.TJ_DTMRFIM, ''), 112), 23) AS maintenance_real_end,
    CASE WHEN bl.matches > 0 THEN bl.name ELSE bs.name END AS equipment_name,
    CASE WHEN sl.matches > 0 THEN sl.name ELSE ss.name END AS service_name,
    CASE WHEN sl.matches > 0 THEN sl.matches ELSE ss.matches END AS service_matches,
    j.identity_count, j.R_E_C_N_O_ AS source_record,
    CONVERT(varchar(33), j.S_T_A_M_P_, 126) AS technical_stamp,
    CONVERT(varchar(33), j.I_N_S_D_T_, 126) AS technical_insert_stamp,
    entries.entry_count, entries.labor_count, entries.material_count, entries.other_count,
    CONVERT(varchar(10), entries.first_start, 23) AS first_entry_date,
    CONVERT(varchar(10), entries.last_end, 23) AS last_entry_end,
    entries.missing_entry_end
FROM missing j
OUTER APPLY (SELECT COUNT(*) AS matches, MAX(b.T9_NOME) AS name FROM dbo.ST9010 b
    WHERE b.D_E_L_E_T_ <> '*' AND b.T9_CODBEM = j.TJ_CODBEM AND b.T9_FILIAL = j.TJ_FILIAL) bl
OUTER APPLY (SELECT COUNT(*) AS matches, MAX(b.T9_NOME) AS name FROM dbo.ST9010 b
    WHERE b.D_E_L_E_T_ <> '*' AND b.T9_CODBEM = j.TJ_CODBEM AND b.T9_FILIAL = '') bs
OUTER APPLY (SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name FROM dbo.ST4010 s
    WHERE s.D_E_L_E_T_ <> '*' AND s.T4_SERVICO = j.TJ_SERVICO AND s.T4_FILIAL = j.TJ_FILIAL) sl
OUTER APPLY (SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name FROM dbo.ST4010 s
    WHERE s.D_E_L_E_T_ <> '*' AND s.T4_SERVICO = j.TJ_SERVICO AND s.T4_FILIAL = '') ss
OUTER APPLY (
    SELECT COUNT_BIG(*) AS entry_count,
        SUM(CASE WHEN l.TL_TIPOREG = 'M' THEN 1 ELSE 0 END) AS labor_count,
        SUM(CASE WHEN l.TL_TIPOREG = 'P' THEN 1 ELSE 0 END) AS material_count,
        SUM(CASE WHEN l.TL_TIPOREG NOT IN ('M', 'P') OR l.TL_TIPOREG IS NULL THEN 1 ELSE 0 END) AS other_count,
        MIN(TRY_CONVERT(date, NULLIF(l.TL_DTINICI, ''), 112)) AS first_start,
        MAX(TRY_CONVERT(date, NULLIF(l.TL_DTFIM, ''), 112)) AS last_end,
        SUM(CASE WHEN TRY_CONVERT(date, NULLIF(l.TL_DTFIM, ''), 112) IS NULL THEN 1 ELSE 0 END) AS missing_entry_end
    FROM dbo.STL010 l WHERE l.D_E_L_E_T_ <> '*' AND l.TL_FILIAL = j.TJ_FILIAL AND l.TL_ORDEM = j.TJ_ORDEM
) entries
ORDER BY j.TJ_FILIAL, j.TJ_ORDEM;

WITH closed AS (
    SELECT TRY_CONVERT(date, NULLIF(j.TJ_DTPRFIM, ''), 112) AS general_end,
        TRY_CONVERT(date, NULLIF(j.TJ_DTMRFIM, ''), 112) AS maintenance_end
    FROM dbo.STJ010 j WHERE j.D_E_L_E_T_ <> '*' AND j.TJ_SITUACA = 'L' AND j.TJ_TERMINO = 'S'
)
SELECT COUNT_BIG(*) AS closed_count,
    SUM(CASE WHEN general_end IS NOT NULL THEN 1 ELSE 0 END) AS valid_general_end,
    SUM(CASE WHEN maintenance_end IS NOT NULL THEN 1 ELSE 0 END) AS valid_maintenance_end,
    SUM(CASE WHEN general_end = maintenance_end THEN 1 ELSE 0 END) AS equal_dates,
    SUM(CASE WHEN general_end IS NOT NULL AND maintenance_end IS NOT NULL AND general_end <> maintenance_end THEN 1 ELSE 0 END) AS different_dates,
    SUM(CASE WHEN general_end IS NULL AND maintenance_end IS NOT NULL THEN 1 ELSE 0 END) AS missing_general_with_maintenance
FROM closed;

SELECT o.name AS object_name, c.name AS column_name, ty.name AS data_type, c.max_length,
    CONVERT(nvarchar(4000), ep.value) AS description,
    HAS_PERMS_BY_NAME('dbo.' + o.name, 'OBJECT', 'SELECT') AS can_select
FROM sys.objects o JOIN sys.columns c ON c.object_id = o.object_id
JOIN sys.types ty ON ty.user_type_id = c.user_type_id
LEFT JOIN sys.extended_properties ep ON ep.major_id = c.object_id AND ep.minor_id = c.column_id AND ep.name = 'MS_Description'
WHERE SCHEMA_NAME(o.schema_id) = 'dbo' AND o.type IN ('U', 'V') AND
    (o.name IN ('STJ010', 'STQ010', 'STL010') OR o.name LIKE 'SX3%'
        OR ((o.name LIKE '%HIST%' OR o.name LIKE '%AUDIT%' OR o.name LIKE '%LOG%') AND EXISTS (
            SELECT 1 FROM sys.columns k WHERE k.object_id = o.object_id AND (k.name LIKE '%ORDEM%' OR k.name LIKE '%ENCERR%'))))
ORDER BY o.name, c.column_id;

SELECT SCHEMA_NAME(t.schema_id) schema_name, t.name table_name, t.temporal_type_desc,
    OBJECT_SCHEMA_NAME(t.history_table_id) history_schema, OBJECT_NAME(t.history_table_id) history_table,
    t.is_tracked_by_cdc,
    CASE WHEN EXISTS (SELECT 1 FROM sys.change_tracking_tables ct WHERE ct.object_id = t.object_id) THEN 1 ELSE 0 END change_tracking,
    tr.name trigger_name, tr.is_disabled,
    CASE WHEN OBJECT_DEFINITION(tr.object_id) IS NOT NULL THEN 1 ELSE 0 END trigger_definition_visible
FROM sys.tables t
LEFT JOIN sys.triggers tr ON tr.parent_id = t.object_id
WHERE t.object_id = OBJECT_ID('dbo.STJ010');

-- Serviço Executado nas mesmas identidades, sem inferir status pelo texto.
SELECT q.TQ_FILIAL, q.TQ_ORDEM, q.TQ_PLANO, q.TQ_TAREFA, q.TQ_ETAPA,
    q.TQ_SEQETA, q.TQ_SEQTARE, q.TQ_OK, q.TQ_CODFUNC, q.R_E_C_N_O_ stage_record,
    CONVERT(varchar(max), q.TQ_OBSERVA) executed_observation
FROM dbo.STQ010 q
WHERE q.D_E_L_E_T_ <> '*' AND q.TQ_OBSERVA IS NOT NULL
    AND EXISTS (
        SELECT 1 FROM OPENJSON(CAST(N'[{"branch":"01","number":"000001"},{"branch":"01","number":"000004"},{"branch":"01","number":"000008"},{"branch":"01","number":"000046"},{"branch":"01","number":"000047"},{"branch":"01","number":"000048"},{"branch":"01","number":"000049"},{"branch":"01","number":"000051"},{"branch":"01","number":"000052"},{"branch":"01","number":"000053"},{"branch":"01","number":"000054"},{"branch":"01","number":"000055"},{"branch":"01","number":"000056"},{"branch":"01","number":"000057"},{"branch":"01","number":"000058"},{"branch":"01","number":"000059"},{"branch":"01","number":"000061"},{"branch":"01","number":"000062"},{"branch":"01","number":"000066"},{"branch":"01","number":"000067"},{"branch":"01","number":"000068"},{"branch":"01","number":"000069"},{"branch":"01","number":"000070"},{"branch":"01","number":"000071"},{"branch":"01","number":"000074"},{"branch":"01","number":"000075"},{"branch":"01","number":"000076"},{"branch":"01","number":"000077"},{"branch":"01","number":"000078"},{"branch":"01","number":"000079"},{"branch":"01","number":"000080"},{"branch":"01","number":"000081"},{"branch":"01","number":"000082"},{"branch":"01","number":"000083"},{"branch":"01","number":"000084"},{"branch":"01","number":"000085"},{"branch":"01","number":"000086"},{"branch":"01","number":"000087"},{"branch":"01","number":"000088"},{"branch":"01","number":"000089"},{"branch":"01","number":"000090"},{"branch":"01","number":"000091"},{"branch":"01","number":"000092"},{"branch":"01","number":"000094"},{"branch":"01","number":"000095"},{"branch":"01","number":"000096"},{"branch":"01","number":"000097"},{"branch":"01","number":"000098"},{"branch":"01","number":"000099"},{"branch":"01","number":"000100"},{"branch":"01","number":"000101"},{"branch":"01","number":"000102"},{"branch":"01","number":"000103"},{"branch":"01","number":"000104"},{"branch":"01","number":"000105"},{"branch":"01","number":"000107"},{"branch":"01","number":"000108"},{"branch":"01","number":"000109"},{"branch":"01","number":"000137"},{"branch":"01","number":"000138"},{"branch":"01","number":"000306"},{"branch":"01","number":"003108"},{"branch":"01","number":"004105"},{"branch":"01","number":"004257"},{"branch":"01","number":"005810"}]' AS nvarchar(max)))
        WITH (branch varchar(100) '$.branch', number varchar(100) '$.number') selected
        WHERE q.TQ_FILIAL = selected.branch AND q.TQ_ORDEM = selected.number
    )
ORDER BY q.TQ_FILIAL, q.TQ_ORDEM, q.TQ_PLANO, q.TQ_TAREFA,
    q.TQ_SEQTARE, q.TQ_ETAPA, q.TQ_SEQETA, q.R_E_C_N_O_;
