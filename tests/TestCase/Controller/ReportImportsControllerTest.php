<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class ReportImportsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testHistoryPageUsesPcmPresentation(): void
    {
        $this->get('/importacoes');
        $this->assertResponseOk();
        $this->assertResponseContains('PCM | IMPORTAÇÃO DE RELATÓRIOS');
        $this->assertResponseContains('HISTÓRICO DE IMPORTAÇÕES');
        $this->assertResponseContains('Importar novo relatório');
    }

    public function testManualPageUsesFriendlyUploadPresentation(): void
    {
        $this->get('/importacoes/manual');
        $this->assertResponseOk();
        $this->assertResponseContains('Importar relatório TOTVS');
        $this->assertResponseContains('Nenhum arquivo selecionado');
        $this->assertResponseContains('Importar relatório');
    }
}
