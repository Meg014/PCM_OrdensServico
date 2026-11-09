<?php
declare(strict_types=1);
namespace App\Service\Protheus;

/** Report-only overrides for exact, audited route/equipment identities. */
final class ActionPlanRouteScope
{
    public static function predicate(): string
    {
        $routes = json_encode(require ROOT . '/resources/data/action-plan-factory-routes.php', JSON_THROW_ON_ERROR);
        $match = "EXISTS (SELECT 1 FROM OPENJSON(N'" . str_replace("'", "''", $routes) . "') WITH (branch varchar(100), equipment varchar(100), cost_center varchar(100), service varchar(100)) route"
            . ' WHERE route.branch COLLATE DATABASE_DEFAULT = j.TJ_FILIAL AND route.equipment COLLATE DATABASE_DEFAULT = j.TJ_CODBEM'
            . ' AND route.cost_center COLLATE DATABASE_DEFAULT = j.TJ_CCUSTO AND route.service COLLATE DATABASE_DEFAULT = j.TJ_SERVICO)';
        return "(f.unidade = '' OR ({$match} AND f.unidade = 'factory') OR (NOT {$match} AND "
            . ProtheusUnit::predicate('j.TJ_CCUSTO', 'f.unidade') . '))';
    }
}
