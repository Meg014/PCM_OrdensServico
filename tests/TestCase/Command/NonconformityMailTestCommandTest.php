<?php
declare(strict_types=1);
namespace App\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\Datasource\FactoryLocator;
use Cake\TestSuite\EmailTrait;
use Cake\TestSuite\TestCase;

final class NonconformityMailTestCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;
    use EmailTrait;

    public function testPreviewRequiresNoCredentialsAndSendsNothing(): void
    {
        $path = TMP . 'nc-cli-preview-test.html';
        $this->exec('nonconformity_mail_test --event five_days --output "' . $path . '"');
        $this->assertExitSuccess(); $this->assertNoMailSent();
        self::assertStringContainsString('Faltam 5 dias', file_get_contents($path));
        self::assertStringContainsString('Prazo final', file_get_contents($path . '.txt'));
        unlink($path); unlink($path . '.txt');
    }
    public function testSingleExplicitRecipientDoesNotEnableOrProcessQueue(): void
    {
        Configure::write('NonconformityMail.enabled', false);
        $q = FactoryLocator::get('Table')->get('NonconformityEmailQueue'); $before = $q->find()->count();
        $this->exec('nonconformity_mail_test --to teste@example.test --send');
        $this->assertExitSuccess(); $this->assertMailCount(1);
        $this->assertMailSentTo('teste@example.test');
        $this->assertMailSentFrom('qualidade@biolasa.com.br');
        $this->assertMailContainsHtml('TESTE CONTROLADO');
        self::assertSame($before, $q->find()->count());
        self::assertFalse(Configure::read('NonconformityMail.enabled'));
    }
    public function testSendWithoutRecipientIsRejectedBeforeDelivery(): void
    {
        $this->exec('nonconformity_mail_test --send');
        $this->assertExitError(); $this->assertNoMailSent(); $this->assertErrorContains('destinatário único');
    }
    public function testInvalidRecipientIsRejected(): void
    {
        $this->exec('nonconformity_mail_test --to invalido --send');
        $this->assertExitError(); $this->assertNoMailSent(); $this->assertErrorContains('destinatário válido');
    }
    public function testOptionalApprovedRecipientIsEnforced(): void
    {
        Configure::write('NonconformityMail.testRecipient', 'aprovado@example.test');
        try {
            $this->exec('nonconformity_mail_test --to outro@example.test --send');
            $this->assertExitError(); $this->assertNoMailSent();
        } finally { Configure::write('NonconformityMail.testRecipient', ''); }
    }
}
