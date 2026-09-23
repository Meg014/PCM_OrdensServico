<?php
declare(strict_types=1);

namespace App\Service\Protheus;

use App\Service\Protheus\Presentation\Availability;
use App\Service\Protheus\Presentation\OrderDetailPresenter;
use App\Service\Protheus\Presentation\OrderSupplementMapper;
use RuntimeException;
use Throwable;

/** Failure boundary for the optional web section. Never returns raw SQL rows/errors. */
final class OrderProtheusService
{
    public const UNAVAILABLE = 'Detalhes do Protheus temporariamente indisponíveis.';

    public function __construct(private ?ProtheusReaderInterface $reader = null, private readonly ?string $areaScope = null)
    {
    }

    public function load(array $snapshot, string $part = 'all', int $page = 1, ?string $selectedOrder = null): array
    {
        $result = ['detail' => null, 'history' => null];
        try {
            if (!in_array($part, ['all', 'history', 'detail'], true) || $page < 1 || $page > 1000000) {
                throw new RuntimeException('Invalid request.');
            }
            $number = rtrim((string)($snapshot['source_order_number'] ?? ''), ' ');
            if ($number === '' || !array_key_exists('branch_code', $snapshot) || $snapshot['branch_code'] === null) {
                throw new RuntimeException('Missing identity.');
            }
            $branch = rtrim((string)$snapshot['branch_code'], ' ');
            $this->reader ??= new ProtheusRepository(budgetSeconds: 8, areaScope: $this->areaScope);
            $mapper = new OrderSupplementMapper();
            $presenter = new OrderDetailPresenter();
            if ($part === 'all') {
                $order = $this->reader->findOrder($number, $branch);
                if ($order === null) {
                    return ['detail' => $presenter->present($snapshot, null, Availability::NotFound)['protheus'], 'history' => null];
                }
                $result['detail'] = $presenter->present($snapshot, $mapper->map($order), Availability::Available)['protheus'];
                if ($result['detail']['state'] !== 'available') {
                    return $result;
                }
                $equipment = $result['detail']['maintenance']['equipment_code'];
            } else {
                // Pagination and historic navigation only read the parent's identity, not its resources.
                $identity = $this->reader->findOrderIdentity($number, $branch);
                if ($identity === null || rtrim((string)$identity['TJ_FILIAL']) !== $branch
                    || rtrim((string)$identity['TJ_ORDEM']) !== $number) {
                    throw new RuntimeException('Parent identity unavailable.');
                }
                $equipment = rtrim((string)$identity['TJ_CODBEM'], ' ');
            }
            if ($part === 'detail') {
                if ($selectedOrder === null || !preg_match('/^[A-Za-z0-9]{1,50}$/D', $selectedOrder)) {
                    throw new RuntimeException('Invalid selection.');
                }
                $order = $this->reader->findOrder($selectedOrder, $branch);
                if ($order === null) {
                    return ['detail' => $presenter->present($snapshot, null, Availability::NotFound)['protheus'], 'history' => null];
                }
                $supplement = $mapper->map($order);
                if (!$equipment || $supplement->maintenance['equipment_code'] !== $equipment) {
                    throw new RuntimeException('Equipment identity mismatch.');
                }
                $context = ['source_order_number' => $selectedOrder, 'branch_code' => $branch];
                $result['detail'] = $presenter->present($context, $supplement, Availability::Available)['protheus'];

                return $result;
            }
            if ($equipment === null || $equipment === '') {
                return $result;
            }
            try {
                $history = $this->reader->findEquipmentHistory($equipment, $branch, $page, 10);
                $items = [];
                foreach ($history['orders'] as $row) {
                    if (rtrim((string)$row['TJ_FILIAL']) !== $branch || rtrim((string)$row['TJ_CODBEM']) !== $equipment) {
                        throw new RuntimeException('History identity mismatch.');
                    }
                    $item = [];
                    foreach (['TJ_ORDEM', 'TJ_FILIAL', 'TJ_SERVICO', 'service_name', 'TJ_CODAREA', 'TJ_SITUACA', 'TJ_TERMINO'] as $key) {
                        $item[$key] = isset($row[$key]) && is_scalar($row[$key]) ? rtrim((string)$row[$key], ' ') : null;
                    }
                    $item['reference_date'] = $mapper->date($row['reference_date'] ?? null);
                    $items[] = $item;
                }
                $result['history'] = [
                    'state' => 'available', 'equipment_code' => $equipment, 'page' => $page,
                    'has_more' => (bool)$history['has_more'], 'orders' => $items,
                ];
            } catch (Throwable) {
                $result['history'] = ['state' => 'unavailable', 'message' => self::UNAVAILABLE];
            }
        } catch (Throwable) {
            $result[$part === 'history' ? 'history' : 'detail'] = ['state' => 'unavailable', 'message' => self::UNAVAILABLE];
        }

        return $result;
    }
}
