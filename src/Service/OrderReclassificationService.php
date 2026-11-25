<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Table\MaintenanceAreasTable;
use App\Service\Protheus\OrderDescriptions;
use App\Service\Protheus\ProtheusRepository;
use Cake\Database\Connection;
use Cake\Database\Driver\Postgres;
use Cake\Datasource\ConnectionManager;
use DomainException;

final class OrderReclassificationService
{
    private Connection $db;

    public function __construct(?Connection $db = null, private ?ProtheusRepository $repository = null)
    {
        $this->db = $db ?? ConnectionManager::get('default');
    }

    public function enabled(): bool
    {
        return $this->db->getDriver() instanceof Postgres;
    }

    private function requirePostgres(): void
    {
        if (!$this->enabled()) throw new DomainException('A reclassificação requer o banco local do PCM em PostgreSQL.');
    }

    public static function areas(): array
    {
        return array_diff_key(MaintenanceAreasTable::FRIENDLY_NAMES, ['INSTRU' => true]);
    }

    public static function numbers(string $input): array
    {
        $parts = preg_split('/[\s,;]+/u', $input, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) throw new DomainException('Informe pelo menos uma O.S.');
        foreach ($parts as $number) {
            if (!preg_match('/^[0-9]{1,50}$/D', $number)) throw new DomainException('Informe somente números de O.S. e separadores válidos.');
        }
        $numbers = array_values(array_unique($parts));
        if (count($numbers) > 500) throw new DomainException('Selecione até 500 O.S. por lote.');
        return $numbers;
    }

    public function activeMap(): array
    {
        if (!$this->enabled()) return [];
        return $this->db->execute('SELECT branch, number, destination FROM order_reclassifications ORDER BY branch, number')->fetchAll('assoc');
    }

    public function preview(string $branch, string $input, string $destination, string $reason): array
    {
        $this->requirePostgres();
        if (!preg_match('/^[A-Za-z0-9]{1,20}$/D', $branch)) throw new DomainException('Informe a filial.');
        if (!isset(self::areas()[$destination])) throw new DomainException('Selecione uma das áreas oficiais.');
        self::reason($reason);
        $numbers = self::numbers($input);
        $repository = $this->repository ??= new ProtheusRepository();
        $source = [];
        foreach ($repository->reclassificationPreview($branch, $numbers) as $row) $source[$row['TJ_ORDEM']][] = $row;
        $active = array_column($this->db->execute('SELECT number FROM order_reclassifications WHERE branch = ?', [$branch])->fetchAll('assoc'), 'number');
        $active = array_fill_keys($active, true);
        $result = [];
        foreach ($numbers as $number) {
            $matches = $source[$number] ?? [];
            $row = $matches[0] ?? [];
            $original = (string)($row['TJ_CODAREA'] ?? '');
            $issue = match (true) {
                !$matches => 'Inexistente',
                count($matches) !== 1 => 'Identidade ambígua no TOTVS',
                ($row['TJ_SITUACA'] ?? '') === 'C' => 'Cancelada',
                isset($active[$number]) => 'Já reclassificada',
                MaintenanceAreasTable::presentationCode($original) === $destination => 'Já pertence à área de destino',
                default => '',
            };
            $result[] = ['number' => $number, 'branch' => $row['TJ_FILIAL'] ?? $branch, 'original' => $original, 'destination' => $destination,
                'description' => $row ? OrderDescriptions::listing($row) : '',
                'equipment' => trim(($row['TJ_CODBEM'] ?? '') . ' ' . ($row['equipment_name'] ?? '')),
                'service' => trim(($row['TJ_SERVICO'] ?? '') . ' ' . ($row['service_name'] ?? '')),
                'status' => $row ? (new WorkOrderStatusResolver())->resolve($row['TJ_SITUACA'], $row['TJ_TERMINO']) : '',
                'issue' => $issue];
        }
        return $result;
    }

    private static function reason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 2000) throw new DomainException('Informe uma justificativa de até 2.000 caracteres.');
    }

    public function confirm(array $preview, array $selection, int $actor): void
    {
        $numbers = self::numbers(implode(' ', $selection));
        sort($numbers, SORT_STRING);
        $allowed = array_column(array_filter($preview['rows'], static fn($r) => $r['issue'] === ''), null, 'number');
        foreach ($numbers as $n) {
            if (!isset($allowed[$n])) throw new DomainException('O.S. fora da prévia. Valide o lote novamente.');
        }
        $rows = $this->preview($preview['branch'], implode(' ', $numbers), $preview['destination'], $preview['reason']);
        $this->db->transactional(function () use ($rows, $allowed, $preview, $actor): void {
            foreach ($rows as $row) {
                if ($row !== $allowed[$row['number']]) throw new DomainException('O lote mudou. Gere uma nova prévia.');
                $params = [$row['branch'], $row['number'], $row['original'], $row['destination']];
                $insert = $this->db->execute('INSERT INTO order_reclassifications (branch,number,original_area,destination)
                    VALUES (?,?,?,?) ON CONFLICT (branch,number) DO NOTHING RETURNING id', $params);
                if (!$insert->fetch()) throw new DomainException('O.S. já reclassificada. Gere uma nova prévia.');
                $this->event($row['branch'], $row['number'], 'Transferência', $row['original'], $row['destination'], $preview['reason'], $actor);
            }
        });
    }

    public function reverse(array $ids, string $reason, int $actor): void
    {
        $this->requirePostgres();
        self::reason($reason);
        $ids = array_unique($ids);
        if (!$ids || count($ids) > 500) throw new DomainException('Selecione de 1 a 500 reclassificações.');
        foreach ($ids as $id) {
            if (!ctype_digit((string)$id) || !(int)$id) throw new DomainException('Seleção inválida.');
        }
        sort($ids, SORT_NUMERIC);
        $this->db->transactional(function () use ($ids, $reason, $actor): void {
            $groups = [];
            foreach ($ids as $id) {
                $row = $this->db->execute('DELETE FROM order_reclassifications WHERE id=? RETURNING branch,number,original_area,destination', [$id])->fetch('assoc');
                if (!$row) throw new DomainException('A seleção mudou. Atualize as reclassificações.');
                $groups[$row['branch']][] = $row;
            }
            $repository = $this->repository ??= new ProtheusRepository();
            foreach ($groups as $branch => $rows) {
                $current = array_column($repository->reclassificationPreview((string)$branch, array_column($rows, 'number')), 'TJ_CODAREA', 'TJ_ORDEM');
                foreach ($rows as $row) {
                    $this->event($row['branch'], $row['number'], 'Reversão', $row['destination'],
                        $current[$row['number']] ?? $row['original_area'], $reason, $actor);
                }
            }
        });
    }

    private function event(string $branch, string $number, string $operation, string $previous, string $destination, string $reason, int $actor): void
    {
        $this->db->insert('order_reclassification_events', ['branch' => $branch, 'number' => $number, 'operation' => $operation,
            'previous_area' => $previous, 'destination' => $destination, 'reason' => trim($reason), 'user_id' => $actor,
            'created' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s')]);
    }

    public function page(bool $history, int $page): array
    {
        $this->requirePostgres();
        $sql = $history
            ? 'SELECT e.*, u.nome AS actor FROM order_reclassification_events e JOIN users u ON u.id=e.user_id ORDER BY e.id DESC'
            : 'SELECT * FROM order_reclassifications ORDER BY id DESC';
        return $this->db->execute($sql . ' LIMIT 51 OFFSET ?', [50 * ($page - 1)], ['integer'])->fetchAll('assoc');
    }
}
