<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Import;

use App\Service\Import\TotvsRowMapper;
use Cake\TestSuite\TestCase;

final class TotvsRowMapperStatusTest extends TestCase
{
    public function testActualStartDoesNotChangeOpenStatus(): void
    {
        $withActualStart = array_fill(0, 57, null);
        $withActualStart[0] = '1';
        $withActualStart[1] = '1001';
        $withActualStart[34] = '28/08/2026';
        $withActualStart[35] = '08:00';
        $withActualStart[40] = 'Não';
        $withActualStart[44] = 'Liberado';
        $withoutActualStart = $withActualStart;
        $withoutActualStart[34] = null;
        $withoutActualStart[35] = null;

        $mapper = new TotvsRowMapper();

        $this->assertSame('EM ABERTO', $mapper->map($withActualStart, 2)['treated_status']);
        $this->assertSame('EM ABERTO', $mapper->map($withoutActualStart, 2)['treated_status']);
        $this->assertNotNull($mapper->map($withActualStart, 2)['maintenance_actual_start']);
    }
}
