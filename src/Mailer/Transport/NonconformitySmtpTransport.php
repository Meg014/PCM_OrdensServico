<?php
declare(strict_types=1);
namespace App\Mailer\Transport;

use App\Mailer\Exception\NonconformityDeliveryException as DeliveryError;
use Cake\Mailer\Message;
use Cake\Mailer\Transport\SmtpTransport;

/** CakePHP SMTP with fixed mailbox, STARTTLS and errors safe for persistence. */
class NonconformitySmtpTransport extends SmtpTransport
{
    public const MAILBOX = 'qualidade@biolasa.com.br';
    private string $phase = 'connection';
    private bool $accepted = false;

    public function send(Message $message): array
    {
        if (array_keys($message->getFrom()) !== [self::MAILBOX]
            || ($message->getSender() && array_keys($message->getSender()) !== [self::MAILBOX])) throw new DeliveryError('Remetente não autorizado. Use qualidade@biolasa.com.br.');
        if ($this->getConfig('host') !== 'smtp.office365.com' || (int)$this->getConfig('port') !== 587 || $this->getConfig('tls') !== true) throw new DeliveryError('Configure smtp.office365.com, porta 587 e STARTTLS (MAIL_ENCRYPTION=tls).', haltBatch: true);
        if ($this->getConfig('username') !== self::MAILBOX || !is_string($this->getConfig('password')) || $this->getConfig('password') === '') throw new DeliveryError('Configure MAIL_USER=qualidade@biolasa.com.br e MAIL_PASSWORD no ambiente do servidor.', haltBatch: true);
        $this->accepted = false; $this->phase = 'connection';
        try {
            return parent::send($message);
        } catch (\Throwable $e) {
            // A failed QUIT must not schedule a second delivery after DATA was accepted.
            if ($this->accepted) return ['headers' => '', 'message' => '', 'status' => 'accepted'];
            $code = preg_match('/SMTP Error:\s*([45]\d{2})\b/i', $e->getMessage(), $matches) ? (int)$matches[1] : null;
            $temporary = $code !== null && $code < 500;
            $suffix = $code === null ? '' : ' (SMTP ' . $code . ')';
            if ($this->phase === 'authentication') throw new DeliveryError('Falha de autenticação SMTP' . $suffix . '. Verifique credencial, SMTP AUTH e políticas da conta com a TI. Nenhuma alternativa de autenticação foi ativada.', retryable: $temporary, haltBatch: true);
            if ($this->phase === 'connection') throw new DeliveryError('Falha de conexão ou STARTTLS com smtp.office365.com:587' . $suffix . '. Verifique DNS, firewall, certificados e OpenSSL.', retryable: $code === null || $temporary, haltBatch: true);
            if ($this->phase === 'recipients' || $code !== null) throw new DeliveryError('Envio SMTP rejeitado antes do aceite' . $suffix . '. Verifique destinatário e autorização do remetente.', retryable: $code === null || $temporary);
            throw new DeliveryError('Resultado SMTP incerto durante DATA. Confira o rastreamento antes de reenviar.', uncertain: true);
        }
    }

    protected function _connect(): void { $this->phase = 'connection'; parent::_connect(); }
    protected function _auth(): void { $this->phase = 'authentication'; parent::_auth(); }
    protected function _sendRcpt(Message $message): void { $this->phase = 'recipients'; parent::_sendRcpt($message); }
    protected function _sendData(Message $message): void
    {
        $this->phase = 'data'; parent::_sendData($message); $this->accepted = true;
    }
}
