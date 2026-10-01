<?php
declare(strict_types=1);

namespace App\Service\Protheus;

use App\Database\Driver\ProtheusReadOnly;
use App\Model\Table\WorkOrderSnapshotsTable;
use App\Service\PcmServiceClassifier;
use App\Service\StreamingXlsxReport;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class ProtheusRepository implements ProtheusReaderInterface
{
    private Connection $connection;

    private ?array $historyUserColumns = null;

    private ?string $historicalOffseasonServices = null;

    private ?float $deadline;

    private string $sectorStage = 'connection';

    /** Technical stage only; used by the explicit CLI diagnostic, never web payloads. */
    public function sectorDiagnosticStage(): string
    {
        return $this->sectorStage;
    }

    public function __construct(?Connection $connection = null, ?int $budgetSeconds = null, private readonly ?string $areaScope = null)
    {
        if ($areaScope !== null && !preg_match('/^[A-Z0-9_-]{1,30}$/D', $areaScope)) {
            throw new InvalidArgumentException('Escopo de área inválido.');
        }
        $connection ??= ConnectionManager::get('protheus');
        if (!$connection instanceof Connection || !$connection->getDriver() instanceof ProtheusReadOnly) {
            throw new RuntimeException('O datasource protheus exige o driver ProtheusReadOnly.');
        }
        $this->connection = $connection;
        $this->deadline = $budgetSeconds === null ? null : microtime(true) + max(1, $budgetSeconds);
        if ($budgetSeconds !== null) {
            $connection->getDriver()->limitQueryTime(min(5, max(1, $budgetSeconds)));
        }
    }

    public function health(): bool
    {
        return (int)$this->read(ProtheusQueries::HEALTH)[0]['connection_ok'] === 1;
    }

    public function findAreas(): array
    {
        return $this->read(ProtheusQueries::AREAS);
    }

    /** Returns distinct cost-center codes for the established open opportunity-stop universe. */
    public function opportunityCostCenters(string $area, array $services): array
    {
        if (($area !== '' && preg_match('/^[A-Z0-9_-]{1,30}$/D', $area) !== 1) || count($services) !== 2) {
            throw new InvalidArgumentException('Oficina ou serviços inválidos.');
        }

        $params = ['service1' => $services[0], 'service2' => $services[1]];
        if ($area !== '') {
            $params['area'] = $area;
        }

        return $this->read(ProtheusSectorQueries::opportunityCostCenters($area !== ''), $params);
    }

    /** Fixed number of reads per equipment; no OS/resource hydration. */
    public function equipmentPortfolio(string $code, string $branch, array $filters, array $windows, int $page, int $limit, bool $export = false, string $sector = ''): array
    {
        if ($code === '' || strlen($code) > 100 || strlen($branch) > 100 || $page < 1 || $limit < 1 || $page > intdiv(PHP_INT_MAX, $limit) || $limit > ($export ? StreamingXlsxReport::BATCH_SIZE : 100)) {
            throw new InvalidArgumentException('Equipamento ou paginação inválidos.');
        }
        $header = $this->master(ProtheusEquipmentQueries::HEADER, $code, $branch);
        $sectorScoped = $sector !== '';
        $params = ['bem' => $code, 'filial' => $branch] + $filters;
        if ($sectorScoped) {
            $params['setor'] = $sector;
        }
        $summary = $this->read(ProtheusEquipmentQueries::summary($sectorScoped), $params + $windows);
        if (count($summary) !== 1 || (int)$summary[0]['identity_count'] > 1) {
            throw new RuntimeException('Histórico incompleto ou identidade ambígua.');
        }
        $rows = $this->read(ProtheusQueries::equipmentPortfolioPage($sectorScoped), $params + [
            'offset' => ($page - 1) * $limit, 'fetch' => $limit + 1,
        ], ['offset' => 'integer', 'fetch' => 'integer']);
        foreach ($rows as $row) {
            if ((int)$row['identity_count'] > 1 || (int)$row['equipment_matches'] > 1 || (int)$row['service_matches'] > 1) {
                throw new RuntimeException('Histórico ambíguo.');
            }
        }

        return ['header' => $header, 'summary' => $summary[0], 'orders' => array_slice($rows, 0, $limit),
            'has_more' => count($rows) > $limit];
    }

    /** Two aggregate reads and one bounded page, never hydration of resources or snapshots. */
    public function sector(array $params, int $page, int $limit, string $start, string $end, array $selection = [], bool $export = false): array
    {
        if ($page < 1 || $limit < 1 || $page > intdiv(PHP_INT_MAX, $limit) || $limit > ($export ? StreamingXlsxReport::BATCH_SIZE : 100)) {
            throw new InvalidArgumentException('Paginação inválida.');
        }
        $areaScoped = isset($params['area']) && $params['area'] !== '';
        $this->sectorStage = 'SQL: ProtheusSectorQueries::aggregates()';
        $aggregate = $this->read(ProtheusSectorQueries::aggregates($areaScoped), $params);
        $this->sectorStage = 'SQL: ProtheusSectorQueries::equipmentRanking()';
        $equipmentRankingParams = $params;
        unset($equipmentRankingParams['cutoff']);
        $equipmentRanking = $this->read(ProtheusSectorQueries::equipmentRanking($areaScoped), $equipmentRankingParams);
        $this->sectorStage = 'SQL: ProtheusSectorQueries::historicalRankings()';
        $historicalRankings = $this->read(ProtheusSectorQueries::historicalRankings($areaScoped), $equipmentRankingParams);
        $backlogParams = $params;
        unset($backlogParams['cutoff']);
        $backlogParams['as_of'] = $selection['as_of'] ?? (new DateTimeImmutable())->format('Y-m-d');
        $this->sectorStage = 'SQL: ProtheusSectorQueries::backlog()';
        $backlogRows = $this->read(ProtheusSectorQueries::backlog($areaScoped), $backlogParams);
        $backlogAge = $selection['backlog_age'] ?? '';
        $pageParams = $backlogAge === '' ? $params : $backlogParams + ['backlog_age' => $backlogAge, 'backlog_bucket' => $backlogAge];
        $season = $selection['season'] ?? '';
        $seasonServices = [];
        if ($season !== '') {
            $this->sectorStage = 'validation: aggregates / season';
            $classifier = new PcmServiceClassifier();
            $groupCount = 0;
            foreach ($aggregate as $row) {
                if (($row['dimension'] ?? '') !== 'cards') {
                    continue;
                }
                if (++$groupCount > 2000 || $row['service_name'] === null) {
                    throw new RuntimeException('Classificação incompleta.');
                }
                $resolved = $classifier->classify($row['TJ_SERVICO'], $row['service_name']) === 'ENTRESSAFRA' ? 'offseason' : 'safra';
                if ($resolved === $season) {
                    $pair = ['code' => $row['TJ_SERVICO'], 'name' => $row['service_name']];
                    $seasonServices[json_encode($pair, JSON_THROW_ON_ERROR)] = $pair;
                }
            }
        }
        $this->sectorStage = $backlogAge === '' ? 'SQL: ProtheusSectorQueries::page(false)' : 'SQL: ProtheusSectorQueries::page(true)';
        $resolveCostCenter = ($selection['services'] ?? []) === ProtheusSectorService::SERVICES['opportunity'];
        $rows = $this->read(
            ProtheusSectorQueries::page($backlogAge !== '', $areaScoped, $resolveCostCenter),
            $pageParams + ['date_start' => $start,
            'date_end' => $end, 'offset' => ($page - 1) * $limit, 'fetch' => $limit + 1,
            'card_status' => $selection['status'] ?? '', 'card_type' => $selection['type'] ?? '',
            'card_service1' => $selection['services'][0] ?? '', 'card_service2' => $selection['services'][1] ?? '',
            'card_season' => $season, 'season_services' => json_encode(array_values($seasonServices), JSON_THROW_ON_ERROR)],
            ['offset' => 'integer', 'fetch' => 'integer'],
        );
        $cardGroups = 0;
        foreach (array_merge($aggregate, $equipmentRanking, $historicalRankings, $backlogRows, $rows) as $index => $row) {
            $this->sectorStage = 'validation: ' . ($index < count($aggregate) ? 'aggregates'
                : ($index < count($aggregate) + count($equipmentRanking) ? 'equipment ranking'
                : ($index < count($aggregate) + count($equipmentRanking) + count($historicalRankings) ? 'historical rankings'
                : ($index < count($aggregate) + count($equipmentRanking) + count($historicalRankings) + count($backlogRows) ? 'backlog' : 'page'))));
            $cardGroups += ($row['dimension'] ?? '') === 'cards' ? 1 : 0;
            if ((int)$row['identity_count'] > 1 || (int)$row['equipment_matches'] > 1 || (int)$row['service_matches'] > 1) {
                $this->sectorStage .= sprintf(
                    ' / identity_count=%d equipment_matches=%d service_matches=%d',
                    $row['identity_count'],
                    $row['equipment_matches'],
                    $row['service_matches'],
                );
                throw new RuntimeException('Identidade ou cadastro ambíguo.');
            }
        }
        if ($cardGroups > 2000) {
            $this->sectorStage = 'validation: aggregates / group limit';
            throw new RuntimeException('Agregação incompleta.');
        }

        return ['aggregates' => $aggregate, 'equipment_ranking' => $equipmentRanking, 'historical_rankings' => $historicalRankings,
            'backlog' => $backlogRows, 'orders' => array_slice($rows, 0, $limit),
            'page' => $page, 'limit' => $limit, 'has_more' => count($rows) > $limit];
    }

    /** Bounded batch export: one SQL row per STL010 entry, never per-order hydration. */
    public function sectorEntries(array $params, string $start, string $end, array $selection, int $page, int $limit): array
    {
        if (
            $page < 1 || $limit < 1 || $page > intdiv(PHP_INT_MAX, $limit)
            || $limit > StreamingXlsxReport::BATCH_SIZE
        ) {
            throw new InvalidArgumentException('Limite de apontamentos inválido.');
        }
        $season = $selection['season'] ?? '';
        $seasonServices = [];
        if ($season !== '') {
            $aggregate = $this->read(ProtheusSectorQueries::aggregates(), $params);
            $classifier = new PcmServiceClassifier();
            $groups = 0;
            foreach ($aggregate as $row) {
                if (($row['dimension'] ?? '') !== 'cards') {
                    continue;
                }
                if (++$groups > 2000 || $row['service_name'] === null) {
                    throw new RuntimeException('Classificação incompleta.');
                }
                $resolved = $classifier->classify($row['TJ_SERVICO'], $row['service_name']) === 'ENTRESSAFRA' ? 'offseason' : 'safra';
                if ($resolved === $season) {
                    $seasonServices[] = ['code' => $row['TJ_SERVICO'], 'name' => $row['service_name']];
                }
            }
        }
        $backlogAge = $selection['backlog_age'] ?? '';
        $queryParams = $params;
        if ($backlogAge !== '') {
            unset($queryParams['cutoff']);
            $queryParams += ['as_of' => $selection['as_of'], 'backlog_age' => $backlogAge, 'backlog_bucket' => $backlogAge];
        }
        $rows = $this->read(ProtheusSectorQueries::entriesPage($backlogAge !== ''), $queryParams + [
            'date_start' => $start, 'date_end' => $end, 'offset' => ($page - 1) * $limit, 'fetch' => $limit + 1,
            'card_status' => $selection['status'] ?? '', 'card_type' => $selection['type'] ?? '',
            'card_service1' => $selection['services'][0] ?? '', 'card_service2' => $selection['services'][1] ?? '',
            'card_season' => $season, 'season_services' => json_encode($seasonServices, JSON_THROW_ON_ERROR),
            'entry_type' => $selection['entry_type'] ?? '', 'professional' => $selection['professional'] ?? '',
        ], ['offset' => 'integer', 'fetch' => 'integer']);
        foreach ($rows as &$row) {
            if (
                (int)$row['identity_count'] > 1 || (int)$row['equipment_matches'] > 1
                || (int)$row['service_matches'] > 1 || (int)$row['professional_matches'] > 1
                || (int)$row['product_matches'] > 1
            ) {
                throw new RuntimeException('Identidade ou cadastro ambíguo.');
            }
            unset(
                $row['identity_count'],
                $row['equipment_matches'],
                $row['service_matches'],
                $row['professional_matches'],
                $row['product_matches'],
                $row['record_id'],
            );
        }
        unset($row);

        return ['entries' => array_slice($rows, 0, $limit), 'has_more' => count($rows) > $limit];
    }

    /** SQL aggregates only: 61 ranking rows or at most 2000 management groups. */
    public function dashboard(array $filters, bool $management = false): array
    {
        $params = [];
        foreach (['filial', 'area', 'bem', 'servico', 'centro', 'tipo', 'situacao', 'termino'] as $key) {
            $value = $filters[$key] ?? '';
            if (!is_string($value) || strlen($value) > 100) {
                throw new InvalidArgumentException('Filtro inválido.');
            }
            $params[$key] = trim($value);
        }
        if (!$management) {
            $unit = $filters['unidade'] ?? '';
            if (!is_string($unit) || !in_array($unit, ['', 'factory', 'mill'], true)) {
                throw new InvalidArgumentException('Unidade invÃ¡lida.');
            }
            $params['unidade'] = $unit;
            $params['offseason_services'] = $this->historicalOffseasonServices();
        }
        if ($management) {
            $params['cutoff'] = str_replace('-', '', WorkOrderSnapshotsTable::OPERATIONAL_START);
        }
        $rows = $this->read($management ? ProtheusQueries::MANAGEMENT : ProtheusQueries::DASHBOARD, $params);
        if ($management && count($rows) > 2000) {
            throw new RuntimeException('Limite de grupos excedido; resultado incompleto.');
        }
        foreach ($rows as &$row) {
            if ((int)$row['identity_count'] > 1) {
                throw new RuntimeException('Identidade de OS ambígua.');
            }
            if (
                !$management && $row['dimension'] === 'equipment'
                && ((int)$row['equipment_matches'] !== 1 || $row['equipment_name'] === null)
            ) {
                throw new RuntimeException('Cadastro de equipamento indisponível ou ambíguo.');
            }
            if (
                !$management && $row['dimension'] === 'service'
                && ((int)$row['service_matches'] !== 1 || $row['service_name'] === null)
            ) {
                throw new RuntimeException('Cadastro de serviço indisponível ou ambíguo.');
            }
            unset($row['identity_count']);
            $row['quantity'] = (int)$row['quantity'];
        }
        unset($row);

        return $rows;
    }

    /** Current portfolio directly from SQL Server; no snapshots or resource hydration. */
    public function findOrders(?string $number = null, ?string $branch = null, ?string $equipment = null, int $page = 1, int $limit = 20, string $costCenter = '', string $dateStart = '', string $dateEnd = '', bool $export = false, string $area = '', string $costCenterMode = '', string $service = '', string $type = '', string $situation = '', string $ending = '', string $historical = '', string $unit = ''): array
    {
        if ($page < 1 || $limit < 1 || $page > intdiv(PHP_INT_MAX, $limit) || $limit > ($export ? StreamingXlsxReport::BATCH_SIZE : 100)) {
            throw new InvalidArgumentException('Paginação inválida.');
        }
        $params = ['offset' => ($page - 1) * $limit, 'fetch' => $limit + 1];
        foreach (['numero' => $number, 'filial' => $branch, 'bem' => $equipment] as $key => $value) {
            if ($value !== null) {
                if (strlen($value) > 100) {
                    throw new InvalidArgumentException('Filtro inválido.');
                }
                $params[$key] = rtrim($value, ' ');
            }
        }
        if (!in_array($costCenterMode, ['', 'exact', 'blank', 'null'], true)) {
            throw new InvalidArgumentException('Filtro de centro de custo inválido.');
        }
        if (!in_array($historical, ['', '1'], true) || !in_array($unit, ['', 'factory', 'mill'], true)
            || ($unit !== '' && $historical !== '1')) {
            throw new InvalidArgumentException('Escopo histÃ³rico invÃ¡lido.');
        }
        $extraFilters = $costCenter !== '' || $costCenterMode !== '' || $dateStart !== '' || $dateEnd !== ''
            || $area !== '' || $service !== '' || $type !== '' || $situation !== '' || $ending !== ''
            || $historical !== '';
        if ($extraFilters) {
            $params += ['centro' => $costCenter, 'centro_modo' => $costCenterMode, 'area' => $area,
                'servico' => $service, 'tipo' => $type, 'situacao' => $situation, 'termino' => $ending,
                'date_start' => $dateStart, 'date_end' => $dateEnd];
            if ($historical === '1') {
                $params['unidade'] = $unit;
                $params['offseason_services'] = $this->historicalOffseasonServices();
            }
        }
        $rows = $this->read(
            ProtheusQueries::orders(
                $number !== null,
                $branch !== null,
                $equipment !== null,
                $extraFilters,
                $historical === '1',
            ),
            $params,
            ['offset' => 'integer', 'fetch' => 'integer'],
        );
        foreach ($rows as &$row) {
            if ((int)$row['identity_count'] > 1 || (int)$row['equipment_matches'] > 1 || (int)$row['service_matches'] > 1) {
                throw new RuntimeException('Identidade ou cadastro ambíguo no Protheus.');
            }
            unset($row['identity_count'], $row['equipment_matches'], $row['service_matches']);
        }
        unset($row);

        return ['orders' => array_slice($rows, 0, $limit), 'page' => $page, 'limit' => $limit, 'has_more' => count($rows) > $limit];
    }

    public function generalEntries(array $filters, int $page, int $limit): array
    {
        if (
            $page < 1 || $limit < 1 || $page > intdiv(PHP_INT_MAX, $limit)
            || $limit > StreamingXlsxReport::BATCH_SIZE
        ) {
            throw new InvalidArgumentException('Paginação inválida.');
        }
        $filters['offseason_services'] = ($filters['historico'] ?? '') === '1'
            ? $this->historicalOffseasonServices() : '[]';
        $rows = $this->read(ProtheusQueries::generalEntries(), $filters + [
            'offset' => ($page - 1) * $limit, 'fetch' => $limit + 1,
        ], ['offset' => 'integer', 'fetch' => 'integer']);
        foreach ($rows as &$row) {
            if (
                (int)$row['identity_count'] > 1 || (int)$row['equipment_matches'] > 1 || (int)$row['service_matches'] > 1
                || (int)$row['professional_matches'] > 1 || (int)$row['product_matches'] > 1
            ) {
                throw new RuntimeException('Identidade ou cadastro ambíguo.');
            }
            unset($row['identity_count'], $row['equipment_matches'], $row['service_matches'], $row['professional_matches'], $row['product_matches']);
        }
        unset($row);

        return ['entries' => array_slice($rows, 0, $limit), 'has_more' => count($rows) > $limit];
    }

    /** Classifies resolved service definitions once and passes exact branch/code pairs to historical SQL. */
    private function historicalOffseasonServices(): string
    {
        if ($this->historicalOffseasonServices !== null) {
            return $this->historicalOffseasonServices;
        }
        $rows = $this->read(ProtheusQueries::HISTORICAL_SERVICE_DEFINITIONS);
        if (count($rows) > 2000) {
            throw new RuntimeException('DefiniÃ§Ãµes de serviÃ§o incompletas.');
        }
        $classifier = new PcmServiceClassifier();
        $services = [];
        foreach ($rows as $row) {
            if ((int)($row['service_matches'] ?? 0) !== 1 || ($row['service_name'] ?? null) === null) {
                throw new RuntimeException('Cadastro de serviÃ§o indisponÃ­vel ou ambÃ­guo.');
            }
            if ($classifier->classify($row['TJ_SERVICO'], $row['service_name']) === 'ENTRESSAFRA') {
                $services[] = ['branch' => rtrim((string)$row['TJ_FILIAL']),
                    'code' => rtrim((string)$row['TJ_SERVICO'])];
            }
        }

        return $this->historicalOffseasonServices = json_encode($services, JSON_THROW_ON_ERROR);
    }

    public function findOrderIdentity(string $numero, string $filial): ?array
    {
        return $this->one(ProtheusQueries::ORDER_IDENTITY, ['numero' => $numero, 'filial' => $filial]);
    }

    /** Returns original Protheus fields; unknown field semantics are deliberately not inferred. */
    public function findOrder(string $numero, ?string $filial = null): ?array
    {
        $numero = trim($numero);
        if ($numero === '' || strlen($numero) > 50 || preg_match('/^[0-9A-Za-z]+$/D', $numero) !== 1) {
            throw new InvalidArgumentException('Número da OS inválido. Preserve os zeros à esquerda.');
        }
        $params = ['numero' => $numero];
        $sql = ProtheusQueries::ORDER;
        if ($filial !== null) {
            $params['filial'] = rtrim($filial);
            $sql = ProtheusQueries::ORDER_BRANCH;
        }
        $order = $this->one($sql, $params);
        if ($order === null) {
            return null;
        }
        $description = $order['pcm_descricao'];
        unset($order['TJ_OBSERVA'], $order['pcm_descricao']);
        $result = [
            'numero' => $numero,
            'dados_principais' => $order,
            'descricao' => $description,
            'equipamento' => $this->master(ProtheusQueries::EQUIPMENT_BRANCH, $order['TJ_CODBEM'], $order['TJ_FILIAL']),
            'servico' => $this->master(ProtheusQueries::SERVICE_BRANCH, $order['TJ_SERVICO'], $order['TJ_FILIAL']),
            'mao_de_obra' => [],
            'materiais' => [],
            'outros_apontamentos' => [],
        ];
        $entries = $this->read(ProtheusQueries::ENTRIES, ['numero' => $numero, 'filial' => $order['TJ_FILIAL']]);
        $professionals = [];
        $products = [];
        foreach ($entries as $entry) {
            $code = $entry['TL_CODIGO'];
            switch ($entry['TL_TIPOREG']) {
                case 'M':
                    if (!array_key_exists($code, $professionals)) {
                        $professionals[$code] = $this->master(ProtheusQueries::PROFESSIONAL_BRANCH, $code, $order['TJ_FILIAL']);
                    }
                    $result['mao_de_obra'][] = [
                        'apontamento' => $entry,
                        'profissional' => $professionals[$code],
                    ];
                    break;
                case 'P':
                    if (!array_key_exists($code, $products)) {
                        $products[$code] = $this->master(ProtheusQueries::PRODUCT_BRANCH, $code, $order['TJ_FILIAL']);
                    }
                    $result['materiais'][] = [
                        'apontamento' => $entry,
                        'produto' => $products[$code],
                    ];
                    break;
                default:
                    $result['outros_apontamentos'][] = $entry;
            }
        }

        return $result;
    }

    /**
     * One bounded page of STJ010, without resource hydration or a global COUNT.
     * null branch includes all branches explicitly identified in each returned row.
     *
     * @return array{equipment_code: string, branch: ?string, page: int, limit: int,
     *   has_more: bool, orders: array, user_columns: array}
     */
    public function findEquipmentHistory(string $equipmentCode, ?string $branch = null, int $page = 1, int $limit = 20): array
    {
        $equipmentCode = rtrim($equipmentCode, ' ');
        $branch = $branch === null ? null : rtrim($branch, ' ');
        if ($equipmentCode === '' || strlen($equipmentCode) > 100 || ($branch !== null && strlen($branch) > 100)) {
            throw new InvalidArgumentException('Código de bem ou filial inválido.');
        }
        if ($page < 1 || $limit < 1 || $limit > 100 || $page > intdiv(PHP_INT_MAX, $limit)) {
            throw new InvalidArgumentException('Use página positiva e limite entre 1 e 100.');
        }
        $this->historyUserColumns ??= $this->read(ProtheusQueries::HISTORY_USER_COLUMNS)[0];
        $params = ['bem' => $equipmentCode, 'offset' => ($page - 1) * $limit, 'fetch' => $limit + 1];
        if ($branch !== null) {
            $params['filial'] = $branch;
        }
        $rows = $this->read(ProtheusQueries::equipmentHistory(
            $branch !== null,
            $this->historyUserColumns['inicio'] !== null,
            $this->historyUserColumns['fim'] !== null,
        ), $params, ['offset' => 'integer', 'fetch' => 'integer']);
        $hasMore = count($rows) > $limit;
        $identities = [];
        foreach ($rows as &$row) {
            if ((int)$row['equipment_matches'] > 1 || (int)$row['service_matches'] > 1) {
                throw new RuntimeException('Cadastro ambíguo no histórico do equipamento.');
            }
            $key = json_encode([$row['TJ_FILIAL'], $row['TJ_ORDEM']], JSON_THROW_ON_ERROR);
            if ((int)$row['identity_count'] > 1 || isset($identities[$key])) {
                throw new RuntimeException('Identidade de OS duplicada no histórico do equipamento.');
            }
            $identities[$key] = true;
            unset($row['equipment_matches'], $row['service_matches'], $row['identity_count']);
        }
        unset($row);

        return [
            'equipment_code' => $equipmentCode, 'branch' => $branch, 'page' => $page,
            'limit' => $limit, 'has_more' => $hasMore, 'orders' => array_slice($rows, 0, $limit),
            'user_columns' => [
                'TJ_USUAINI' => $this->historyUserColumns['inicio'] !== null,
                'TJ_USUAFIM' => $this->historyUserColumns['fim'] !== null,
            ],
        ];
    }

    /** Same local/shared priority as history, never another nonblank branch. */
    private function master(string $sql, string $code, string $branch): ?array
    {
        $row = $this->one($sql, ['codigo' => $code, 'filial' => $branch]);

        return $row ?? ($branch !== '' ? $this->one($sql, ['codigo' => $code, 'filial' => '']) : null);
    }

    private function one(string $sql, array $params): ?array
    {
        $rows = $this->read($sql, $params);
        if (count($rows) > 1) {
            throw new RuntimeException('Chave Protheus ambígua. Informe --filial para a OS; valide o compartilhamento dos cadastros se persistir.');
        }

        return $rows[0] ?? null;
    }

    /**
     * Shared read boundary for detail and future paginated equipment-history queries.
     * Future SQL must also be registered in ProtheusQueries; history should call read()
     * directly rather than findOrder() per row (which loads all resource entries).
     */
    private function read(string $sql, array $params = [], array $types = []): array
    {
        if ($this->areaScope !== null) {
            $scopedSql = ProtheusQueries::withAreaScope($sql);
            if ($scopedSql !== $sql) {
                $sql = $scopedSql;
                $params['scope_area'] = $this->areaScope;
            }
        }
        if ($this->deadline !== null && microtime(true) >= $this->deadline) {
            throw new RuntimeException('Tempo de consulta complementar excedido.');
        }
        $statement = $this->connection->execute($sql, $params, $types + array_fill_keys(array_keys($params), 'string'));
        try {
            $rows = $statement->fetchAll('assoc');
        } finally {
            $statement->closeCursor();
        }
        foreach ($rows as &$row) {
            foreach ($row as &$value) {
                if (is_string($value)) {
                    $value = rtrim($value, ' ');
                }
            }
            unset($value);
        }
        unset($row);

        return $rows;
    }
}
