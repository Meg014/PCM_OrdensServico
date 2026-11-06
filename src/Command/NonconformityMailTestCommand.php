<?php
declare(strict_types=1);
namespace App\Command;

use App\Mailer\Exception\NonconformityDeliveryException;
use App\Service\NonconformityEmailService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Log\Log;

/** Never queries or processes the notification queue or the list of responsible people. */
final class NonconformityMailTestCommand extends Command
{
    public static function defaultName(): string { return 'nonconformity_mail_test'; }
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription('Prévia offline por padrão. --send envia somente uma mensagem ilustrativa ao endereço informado; não processa a fila.')
            ->addOption('to', ['help' => 'Destinatário único. Obrigatório com --send.'])
            ->addOption('event', ['default' => 'assigned', 'choices' => array_keys(NonconformityEmailService::MESSAGES)])
            ->addOption('send', ['boolean' => true, 'help' => 'Enviar exatamente um teste. Não habilita notificações automáticas.'])
            ->addOption('output', ['help' => 'Caminho HTML para prévia offline; gera também arquivo .txt.']);
    }
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        try {
            if ($args->getOption('send') && !$args->getOption('to')) throw new NonconformityDeliveryException('Informe explicitamente o destinatário único em --to.');
            $to = (string)($args->getOption('to') ?: 'destinatario@example.test');
            $allowed = (string)Configure::read('NonconformityMail.testRecipient', '');
            if ($args->getOption('send') && $allowed !== '' && strcasecmp($to, $allowed) !== 0) throw new NonconformityDeliveryException('Destinatário diferente de NC_MAIL_TEST_RECIPIENT.');
            $event = (string)$args->getOption('event');
            $mailer = (new NonconformityEmailService())->testMessage($to, $event);
            if ($args->getOption('send')) {
                $mailer->deliver();
                $provider = (string)Configure::read('NonconformityMail.provider', 'smtp');
                Log::info('NC teste controlado: modelo=' . $event . ' provider=' . $provider . ' resultado=aceito');
                $io->success($provider === 'debug' ? 'Teste simulado; nenhum e-mail real foi enviado.' : 'Uma mensagem de teste aceita pelo SMTP. Confira recebimento e rastreamento.');
            } else {
                $mailer->render();
                $path = (string)($args->getOption('output') ?: TMP . 'nc-email-preview-' . $event . '.html');
                if (file_put_contents($path, $mailer->getMessage()->getBodyHtml(), LOCK_EX) === false
                    || file_put_contents($path . '.txt', $mailer->getMessage()->getBodyText(), LOCK_EX) === false) throw new \RuntimeException('Preview write failed');
                $io->success('Prévia offline: ' . $path . ' e ' . $path . '.txt. Nenhum envio realizado.');
            }
            return static::CODE_SUCCESS;
        } catch (\Throwable $e) {
            $safe = $e instanceof NonconformityDeliveryException ? $e->getMessage() : 'Falha ao preparar o teste. Verifique URL pública, template, permissões de arquivo e configuração de e-mail.';
            Log::error('NC teste controlado: ' . $safe);
            $io->error($safe);
            return static::CODE_ERROR;
        }
    }
}
