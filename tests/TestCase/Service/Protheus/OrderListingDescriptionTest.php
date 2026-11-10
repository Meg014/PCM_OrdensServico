<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Service\Protheus\OrderDescriptions;
use PHPUnit\Framework\TestCase;

final class OrderListingDescriptionTest extends TestCase
{
    public function testOnlyRouteListingUsesEquipmentNameWithoutCodeOrChangingSource(): void
    {
        foreach (['PREVELE FAB 01', 'PREVLUB FAB 03', 'PREVMEC FAB 07', ' prevmec fab 07 '] as $equipment) {
            $row = ['TJ_CODBEM' => $equipment, 'equipment_name' => 'ROTA 07 - RECEPÇÃO', 'descricao' => 'Descrição original'];
            self::assertSame('ROTA 07 - RECEPÇÃO', OrderDescriptions::listing($row));
            self::assertSame('Descrição original', $row['descricao']);
            self::assertSame('Descrição original', OrderDescriptions::display($row['descricao']));
        }
    }

    public function testOrdinaryOrdersKeepCurrentDescriptionAndChecklistCleaning(): void
    {
        $text = "Trocar correia\nferramentas limpas antes do serviço? ( ) sim ( ) não ( ) n/a";
        foreach (['ELV 80 016', 'PREVELE USI 01', 'PREVMEC 07', ''] as $equipment) {
            self::assertSame('Trocar correia', OrderDescriptions::listing([
                'TJ_CODBEM' => $equipment, 'equipment_name' => 'Nome do equipamento', 'descricao' => $text,
            ]));
        }
        self::assertSame('Não informado', OrderDescriptions::listing(['TJ_CODBEM' => 'PREVLUB FAB 03', 'descricao' => 'Não usar como alternativa']));
    }
}
