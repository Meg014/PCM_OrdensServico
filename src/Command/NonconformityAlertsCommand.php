<?php
declare(strict_types=1);
namespace App\Command;

use App\Service\NonconformityService;
use App\Service\NonconformityEmailService;
use Cake\Console\ConsoleOptionParser;
use Cake\Log\Log;
use Cake\Core\Configure;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

final class NonconformityAlertsCommand extends Command
{
    public static function defaultName(): string { return 'nonconformity_alerts'; }
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->addOption('dry-run', ['boolean' => true, 'help' => 'Somente leitura: informa data, configuração pública e contadores da fila; não gera eventos nem envia.'])
            ->addOption('queue-only', ['boolean' => true, 'help' => 'Gera eventos sem enviar e-mails.'])
            ->addOption('limit', ['default' => '100', 'help' => 'Máximo de envios por execução (1 a 1000).']);
    }
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        try {
            if ($args->getOption('dry-run')) {
                $io->out(json_encode((new NonconformityEmailService())->report(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return static::CODE_SUCCESS;
            }
            $limit = filter_var($args->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
            if ($limit === false) throw new \InvalidArgumentException('Limite inválido.');
            $internal = (new NonconformityService())->generate();
            $io->success('Alertas criados: ' . $internal);
            $mail = new NonconformityEmailService();
            $queued = $mail->generate();
            $io->out('E-mails agendados: ' . $queued);
            Log::info('NC rotina: alertas=' . $internal . ' agendados=' . $queued);
            if (!$args->getOption('queue-only') && Configure::read('NonconformityMail.enabled', false)) {
                $result = $mail->process($limit);
                $io->out(json_encode($result, JSON_THROW_ON_ERROR));
                Log::info('NC rotina: ' . json_encode($result, JSON_THROW_ON_ERROR));
                if ($result['failed']) return static::CODE_ERROR;
            } else {
                $io->out('Envio não executado (queue-only ou NC_MAIL_ENABLED desativado).');
            }
            return static::CODE_SUCCESS;
        } catch (\Throwable $e) {
            Log::error('Rotina NC falhou: ' . get_class($e));
            $io->error('Falha na rotina de Não Conformidades. Consulte os logs, o banco PCM e a configuração de e-mail.');
            return static::CODE_ERROR;
        }
    }
}
