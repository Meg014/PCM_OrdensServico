<?php
declare(strict_types=1);
namespace App\Test\TestCase\Service;

use App\Service\Protheus\ActionPlanQuery;
use App\Service\Protheus\ActionPlanStageQueries;
use App\Service\Protheus\ProtheusQueries;
use PHPUnit\Framework\TestCase;

final class ActionPlanStageInvestigationTest extends TestCase
{
    public function testOnlyFixedReadOnlyMetadataQueriesAreAllowed(): void
    {
        foreach ([ActionPlanStageQueries::PERMISSIONS, ActionPlanStageQueries::METADATA] as $sql) {
            self::assertTrue(ProtheusQueries::allows($sql));
            self::assertFalse(ProtheusQueries::allows($sql . '; DELETE FROM dbo.STJ010'));
        }
        foreach (['ST5010', 'STH010', 'STQ010', 'STX010'] as $table) self::assertStringContainsString($table, ActionPlanStageQueries::PERMISSIONS);
        // Metadata inspection must not introduce a speculative stage-data join into the report.
        self::assertStringNotContainsString('FROM dbo.STQ010', ActionPlanQuery::sql());
        self::assertStringNotContainsString('FROM dbo.ST5010', ActionPlanQuery::sql());
    }

    public function testManualScriptsAreReadOnlyAndScopeTheStageSampleByBranchAndOrder(): void
    {
        foreach (['permissoes', 'amostra'] as $part) {
            $sql = file_get_contents(ROOT . '/docs/sql/plano-de-acao-etapas-' . $part . '.sql');
            $sql = preg_replace('~/\*.*?\*/|--[^\r\n]*~s', '', $sql);
            self::assertDoesNotMatchRegularExpression('/\b(?:INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|MERGE|EXEC|GRANT|REVOKE)\b/i', $sql);
        }
        self::assertStringContainsString('q.TQ_FILIAL = @Filial AND q.TQ_ORDEM = @Ordem', $sql);
        self::assertStringContainsString('j.TJ_FILIAL = q.TQ_FILIAL AND j.TJ_ORDEM = q.TQ_ORDEM', $sql);
        self::assertStringContainsString("'007334'", $sql);
        self::assertStringContainsString('CONVERT(varchar(max), q.TQ_OBSERVA)', $sql);
        self::assertStringNotContainsString('TL_OBSERVA', $sql);
    }
}
