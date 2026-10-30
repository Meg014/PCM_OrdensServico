<?php
declare(strict_types=1);
namespace App\Command;

use App\Service\NonconformityService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

final class NonconformityAlertsCommand extends Command
{
    public static function defaultName(): string { return 'nonconformity_alerts'; }
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        try {
            $io->success('Alertas criados: ' . (new NonconformityService())->generate());
            return static::CODE_SUCCESS;
        } catch (\Throwable) {
            $io->error('Falha ao gerar alertas de Não Conformidades. Verifique o banco PCM e execute novamente.');
            return static::CODE_ERROR;
        }
    }
}
