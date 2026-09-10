<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\WorkOrderStatusResolver;
use Cake\TestSuite\TestCase;

final class WorkOrderStatusResolverTest extends TestCase
{
    private WorkOrderStatusResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new WorkOrderStatusResolver();
    }

    public function testCancellationHasPrecedence(): void
    {
        $this->assertSame('CANCELADA', $this->resolver->resolve(' Cancelada ', 'Sim'));
    }

    public function testCompleted(): void
    {
        $this->assertSame('FECHADA', $this->resolver->resolve('Liberado', 'Sim'));
    }

    public function testActualStartDoesNotChangeOpenStatus(): void
    {
        $this->assertSame('EM ABERTO', $this->resolver->resolve('Liberado', 'Não'));
    }
}
