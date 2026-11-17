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

    public function testProtheusCodesFollowEndingSWithCancellationPrecedenceWithoutDates(): void
    {
        foreach (['L', 'P', null] as $situation) {
            $this->assertSame('FECHADA', $this->resolver->resolve($situation, 'S'));
        }
        $this->assertSame('CANCELADA', $this->resolver->resolve('C', 'S'));
        $this->assertSame('EM ABERTO', $this->resolver->resolve('L', 'N'));
    }
}
