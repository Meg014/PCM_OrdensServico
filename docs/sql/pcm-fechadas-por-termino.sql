-- Sem periodo: TJ_TERMINO S, canceladas excluidas, identidades distintas.
WITH named AS (
    SELECT j.TJ_FILIAL, j.TJ_ORDEM,
        CASE WHEN UPPER(RTRIM(j.TJ_CODAREA)) = 'INSTRU' THEN 'ELETRI' ELSE UPPER(RTRIM(j.TJ_CODAREA)) END AS area,
        CASE WHEN sl.matches > 0 THEN sl.name ELSE ss.name END AS service_name
    FROM dbo.STJ010 j
    OUTER APPLY (SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name FROM dbo.ST4010 s
        WHERE s.T4_FILIAL = j.TJ_FILIAL AND s.T4_SERVICO = j.TJ_SERVICO AND s.D_E_L_E_T_ <> '*') sl
    OUTER APPLY (SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name FROM dbo.ST4010 s
        WHERE s.T4_FILIAL = '' AND s.T4_SERVICO = j.TJ_SERVICO AND s.D_E_L_E_T_ <> '*') ss
    WHERE j.D_E_L_E_T_ <> '*' AND j.TJ_TERMINO = 'S' AND (j.TJ_SITUACA IS NULL OR j.TJ_SITUACA <> 'C')
)
SELECT area, CASE WHEN service_name COLLATE Latin1_General_100_CI_AI LIKE '%ENTRESSAFRA%'
    THEN 'ENTRESSAFRA' ELSE 'SAFRA' END AS season,
    COUNT_BIG(DISTINCT RTRIM(TJ_FILIAL) + CHAR(31) + RTRIM(TJ_ORDEM)) AS closed_count
FROM named GROUP BY area, CASE WHEN service_name COLLATE Latin1_General_100_CI_AI LIKE '%ENTRESSAFRA%'
    THEN 'ENTRESSAFRA' ELSE 'SAFRA' END
ORDER BY area, season;
