<?php
declare(strict_types=1);
namespace App\Test\TestCase\Mailer\Transport;

use App\Mailer\Exception\NonconformityDeliveryException;
use App\Mailer\Transport\NonconformitySmtpTransport;
use Cake\Mailer\Message;
use Cake\Network\Socket;
use Cake\TestSuite\TestCase;

final class NonconformitySmtpTransportTest extends TestCase
{
    private array $writes = [];
    private function transport(array $responses, bool $connect = true, bool $quitFailure = false): NonconformitySmtpTransport
    {
        $socket = $this->createMock(Socket::class);
        $socket->method('connect')->willReturn($connect);
        $socket->method('isConnected')->willReturn(false);
        if ($connect) $socket->expects(self::once())->method('enableCrypto')->with('tls');
        $socket->method('read')->willReturnCallback(static function () use (&$responses) { return array_shift($responses); });
        $socket->method('write')->willReturnCallback(function ($data) use ($quitFailure) {
            $this->writes[] = $data;
            if ($quitFailure && $data === "QUIT\r\n") throw new \RuntimeException('Socket closed after accepted DATA');
            return strlen($data);
        });
        $transport = new class(['host' => 'smtp.office365.com', 'port' => 587, 'tls' => true, 'username' => 'qualidade@biolasa.com.br', 'password' => 'test-only-secret', 'authType' => 'LOGIN']) extends NonconformitySmtpTransport {
            public Socket $fake;
            protected function _generateSocket(): void { $this->_socket = $this->fake; }
        };
        $transport->fake = $socket;
        return $transport;
    }
    private function replies(): array
    {
        return ["220 Ready\r\n", "250 AUTH LOGIN\r\n", "220 Start TLS\r\n", "250 AUTH LOGIN\r\n", "334 Username\r\n", "334 Password\r\n", "235 Authenticated\r\n", "250 Sender\r\n", "250 Recipient\r\n", "354 Send data\r\n", "250 Accepted\r\n"];
    }
    private function message(): Message
    {
        return (new Message())->setFrom(['qualidade@biolasa.com.br' => 'Biolasa | PCM Qualidade'])->setTo('teste@example.test')->setSubject('Teste controlado')->setBody(['text' => 'Conteúdo de teste.']);
    }
    public function testCakeSmtpUsesStartTlsBeforeAuthentication(): void
    {
        $result = $this->transport($this->replies())->send($this->message());
        self::assertStringContainsString('Conteúdo', $result['message']);
        $tls = array_search("STARTTLS\r\n", $this->writes, true);
        $auth = array_search("AUTH LOGIN\r\n", $this->writes, true);
        self::assertIsInt($tls); self::assertIsInt($auth); self::assertLessThan($auth, $tls);
        self::assertCount(1, array_filter($this->writes, static fn ($v) => str_starts_with($v, 'RCPT TO:')));
    }
    public function testAuthenticationErrorIsSafeAndDoesNotRetry(): void
    {
        $responses = $this->replies(); $responses[6] = "535 Secret test-only-secret rejected\r\n";
        try { $this->transport($responses)->send($this->message()); self::fail('Expected auth error'); }
        catch (NonconformityDeliveryException $e) {
            self::assertFalse($e->retryable); self::assertFalse($e->uncertain); self::assertNull($e->getPrevious());
            self::assertStringContainsString('autenticação SMTP', $e->getMessage());
            self::assertStringNotContainsString('test-only-secret', $e->getMessage());
        }
        self::assertFalse((bool)array_filter($this->writes, static fn ($v) => str_starts_with($v, 'MAIL FROM:')));
    }
    public function testConnectionFailureCanRetryWithoutLeakingDetails(): void
    {
        try { $this->transport([], false)->send($this->message()); self::fail(); }
        catch (NonconformityDeliveryException $e) { self::assertTrue($e->retryable); self::assertFalse($e->uncertain); }
    }
    public function testTimeoutAfterDataIsUncertainAndMustNotBlindlyRetry(): void
    {
        $responses = $this->replies(); array_pop($responses);
        try { $this->transport($responses)->send($this->message()); self::fail(); }
        catch (NonconformityDeliveryException $e) { self::assertTrue($e->uncertain); self::assertFalse($e->retryable); }
    }
    public function testQuitFailureDoesNotDuplicateAcceptedMessage(): void
    {
        self::assertSame('accepted', $this->transport($this->replies(), quitFailure: true)->send($this->message())['status']);
    }
    public function testExplicitTemporaryDataRejectionCanRetry(): void
    {
        $responses = $this->replies(); $responses[10] = "451 Temporary rejection\r\n";
        try { $this->transport($responses)->send($this->message()); self::fail(); }
        catch (NonconformityDeliveryException $e) { self::assertTrue($e->retryable); self::assertFalse($e->uncertain); }
    }
    public function testWrongSenderIsRejectedBeforeNetwork(): void
    {
        $t = new NonconformitySmtpTransport();
        $this->expectException(NonconformityDeliveryException::class);
        $t->send($this->message()->setFrom('outra@example.test'));
    }
    public function testMissingPasswordIsRejectedBeforeNetwork(): void
    {
        $t = new NonconformitySmtpTransport(['host' => 'smtp.office365.com', 'port' => 587, 'tls' => true, 'username' => 'qualidade@biolasa.com.br']);
        $this->expectException(NonconformityDeliveryException::class);
        $t->send($this->message());
    }
}
