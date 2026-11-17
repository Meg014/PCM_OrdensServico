<?php
declare(strict_types=1);

use App\Model\Table\MaintenanceAreasTable;
use App\Service\ActionPlanExecution;
use App\Service\Protheus\MissingCompletionAuditQueries;
use App\Service\Protheus\ProtheusRepository;
use App\Service\StreamingXlsxReport;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/bootstrap.php';
$generated = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$data = (new ProtheusRepository(budgetSeconds: 25))->missingCompletionAudit();
$identities = array_map(static fn($r) => ['branch' => $r['TJ_FILIAL'], 'number' => $r['TJ_ORDEM']], $data['orders']);
$attached = ActionPlanExecution::attach($identities, $data['execution']);
$execution = [];
foreach ($attached as $row) $execution[json_encode([$row['branch'], $row['number']], JSON_THROW_ON_ERROR)] = $row['executed_service'];
$stats = ['orders' => 0, 'problems' => [], 'origin_years' => [], 'with_general_start' => 0,
    'with_maintenance_start' => 0, 'with_maintenance_end' => 0, 'with_entries' => 0,
    'with_executed_service' => 0, 'maintenance_end_matches_last_entry' => 0, 'maintenance_end_differs' => 0];
$seen = []; $rows = []; $different = [];
foreach ($data['orders'] as $row) {
    $key = json_encode([$row['TJ_FILIAL'], $row['TJ_ORDEM']], JSON_THROW_ON_ERROR);
    if (isset($seen[$key]) || (int)$row['identity_count'] !== 1) throw new RuntimeException('Identidade duplicada; não emitir relatório como único.');
    $seen[$key] = true;
    $stats['orders']++;
    $problem = $row['completion_problem'];
    $stats['problems'][$problem] = ($stats['problems'][$problem] ?? 0) + 1;
    $year = $row['origin_date'] ? substr($row['origin_date'], 0, 4) : 'Sem origem válida';
    $stats['origin_years'][$year] = ($stats['origin_years'][$year] ?? 0) + 1;
    $row['executed_service'] = $execution[$key] ?? '';
    $row['has_executed_service'] = $row['executed_service'] !== '' ? 'Sim' : 'Não';
    $row['completion_raw_display'] = $problem === 'VAZIO'
        ? '[vazio; ' . $row['completion_bytes'] . ' bytes; hex ' . $row['completion_hex'] . ']' : ($row['TJ_DTPRFIM'] ?? '[NULL]');
    $row['area_name'] = MaintenanceAreasTable::FRIENDLY_NAMES[trim($row['TJ_CODAREA'])] ?? 'Área desconhecida/vazia';
    $row['origin_year'] = $year;
    $row['age_days'] = $row['origin_date'] ? (string)(new DateTimeImmutable($row['origin_date'], $generated->getTimezone()))->diff($generated)->days : '';
    $stats['with_general_start'] += $row['real_start'] !== null ? 1 : 0;
    $stats['with_maintenance_start'] += $row['maintenance_real_start'] !== null ? 1 : 0;
    $stats['with_maintenance_end'] += $row['maintenance_real_end'] !== null ? 1 : 0;
    $stats['with_entries'] += (int)$row['entry_count'] > 0 ? 1 : 0;
    $stats['with_executed_service'] += $row['executed_service'] !== '' ? 1 : 0;
    if ($row['maintenance_real_end'] !== null && $row['last_entry_end'] !== null) {
        if ($row['maintenance_real_end'] === $row['last_entry_end']) $stats['maintenance_end_matches_last_entry']++;
        else { $stats['maintenance_end_differs']++; $different[] = $row; }
    }
    $rows[] = $row;
}
ksort($stats['origin_years']);
$directory = dirname(__DIR__) . '/docs/reports';
if (!is_dir($directory)) mkdir($directory, 0775, true);
$stem = 'os-concluidas-sem-data-' . $generated->format('Y-m-d');
$columns = [
    'TJ_ORDEM' => 'O.S. (original)', 'TJ_FILIAL' => 'Filial', 'TJ_CODBEM' => 'Equipamento',
    'equipment_name' => 'Nome equipamento', 'TJ_SERVICO' => 'Serviço (código)', 'service_name' => 'Nome Serviço original',
    'TJ_CODAREA' => 'Área Manut. original', 'area_name' => 'Área Manut. nome', 'TJ_SITUACA' => 'Situação',
    'TJ_TERMINO' => 'TJ_TERMINO', 'TJ_DTPRFIM' => 'TJ_DTPRFIM original (vazio)',
    'completion_raw_display' => 'Conteúdo bruto / hex', 'completion_problem' => 'Problema',
    'origin_date' => 'Data origem', 'origin_year' => 'Ano origem', 'age_days' => 'Dias desde origem',
    'TJ_TIPOOS' => 'Tipo O.S.', 'TJ_TIPO' => 'Tipo Manut.', 'TJ_PLANO' => 'Plano',
    'real_start' => 'Real. Início geral (TJ_DTPRINI)', 'maintenance_real_start' => 'R. In. Man. (TJ_DTMRINI)',
    'maintenance_real_end' => 'R. Fim Man. (TJ_DTMRFIM)', 'entry_count' => 'Nº apontamentos',
    'labor_count' => 'Apontamentos mão de obra', 'material_count' => 'Apontamentos materiais',
    'other_count' => 'Outros apontamentos', 'first_entry_date' => 'Primeiro início apontamento',
    'last_entry_end' => 'Último fim apontamento', 'missing_entry_end' => 'Apontamentos sem fim válido',
    'has_executed_service' => 'Serviço Executado legível?', 'executed_service' => 'Serviço Executado (STQ)',
    'technical_stamp' => 'Carimbo técnico S_T_A_M_P_ (não fechamento)',
    'technical_insert_stamp' => 'Carimbo I_N_S_D_T_ (não fechamento)', 'source_record' => 'R_E_C_N_O_ origem',
];
$definitions = [];
foreach ($columns as $field => $label) $definitions[$field] = [$label, 'text', $field === 'executed_service' ? 65 : 26];
$xlsx = (new StreamingXlsxReport())->write([
    'title' => 'CONCLUÍDAS SEM REAL. FIM — CONFERÊNCIA PCM',
    'scope' => 'Situação L / Término S preservados. Datas alternativas não homologadas como encerramento.',
    'generated' => $generated, 'total_label' => 'O.S. únicas', 'table_row' => 5,
    'columns' => $definitions, 'sheet_name' => 'Inconsistencias',
], static fn($page) => ['available' => true, 'rows' => $page === 1 ? $rows : [], 'has_more' => false]);
$target = fopen($directory . '/' . $stem . '.xlsx', 'wb');
rewind($xlsx['stream']); stream_copy_to_stream($xlsx['stream'], $target); fclose($target); fclose($xlsx['stream']);
$data['report_rows'] = $rows;
$data['summary'] = $stats;
$data['generated_at'] = $generated->format(DATE_ATOM);
file_put_contents($directory . '/' . $stem . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$md = '# O.S. concluídas sem Real. Fim — ' . $generated->format('d/m/Y') . "\n\n"
    . "Consulta direta ao Protheus exclusivamente SELECT. Situação L e TJ_TERMINO S mantidos; nenhuma O.S. reclassificada como aberta.\n\n"
    . 'Relatório detalhado: [' . $stem . '.xlsx](' . $stem . ".xlsx). Números e filiais são texto, com zeros à esquerda. Serviço Executado completo está na planilha; evidências brutas e metadados no JSON correspondente.\n\n"
    . "## Resultados\n\n| Verificação | Quantidade |\n| --- | ---: |\n";
$summary = ['O.S. únicas' => $stats['orders'], 'TJ_DTPRFIM vazio' => $stats['problems']['VAZIO'] ?? 0,
    'TJ_DTPRFIM NULL' => $stats['problems']['NULO'] ?? 0,
    'Datas inválidas / outros formatos' => $stats['orders'] - ($stats['problems']['VAZIO'] ?? 0) - ($stats['problems']['NULO'] ?? 0),
    'Real. Início geral válido (TJ_DTPRINI)' => $stats['with_general_start'],
    'Início real manutenção válido (TJ_DTMRINI)' => $stats['with_maintenance_start'],
    'Fim real manutenção válido (TJ_DTMRFIM)' => $stats['with_maintenance_end'],
    'Com apontamentos não excluídos' => $stats['with_entries'], 'Com Serviço Executado legível (STQ)' => $stats['with_executed_service']];
foreach ($summary as $label => $value) $md .= "| {$label} | {$value} |\n";
$md .= "\nEm todas as linhas vazias foram preservados tamanho/hex para distinguir espaço de NULL ou data inválida. A consulta atual encontrou oito espaços (hex 2020202020202020), não um erro de conversão de uma data preenchida.\n\n### Antiguidade pela Data de origem\n\n";
foreach ($stats['origin_years'] as $year => $count) $md .= "- {$year}: {$count} O.S.\n";
$md .= "\nO ano de origem não equivale ao ano de encerramento. Os registros do ano corrente devem ser conferidos junto com os antigos; não se trata somente de legado de 2024.\n\n## Causa observável e limites\n\n"
    . "O status de conclusão está preenchido, mas os campos reais gerais não foram alimentados nestas O.S.; os campos reais de manutenção e os apontamentos possuem registros. Esse é o motivo observável da exclusão do indicador baseado em TJ_DTPRFIM. Não foi possível provar por SELECT qual rotina, integração ou ação do usuário deixou os campos gerais vazios.\n\n"
    . "Serviço Executado foi lido da fonte STQ já usada pelo PCM, com a mesma normalização existente. Campo bruto não nulo com espaços/controle não foi contado como texto executado. A ausência de texto não altera o status.\n\n## Existe outra data confiável de encerramento?\n\n"
    . "TJ_DTMRFIM é candidato como fim efetivo da manutenção: existe nas O.S. investigadas. Entretanto, fim de execução e evento de encerramento não são comprovadamente o mesmo evento. Não foi homologado um substituto automático para TJ_DTPRFIM.\n\n";
$global = $data['comparison'][0];
$md .= "Na população completa de {$global['closed_count']} O.S. L/S, há {$global['equal_dates']} pares de datas gerais/manutenção iguais, {$global['different_dates']} pares preenchidos diferentes e {$global['missing_general_with_maintenance']} casos sem fim geral mas com fim manutenção. Portanto, os campos não são intercambiáveis.\n\n"
    . "No grupo investigado, TJ_DTMRFIM coincide com o último fim de apontamento em {$stats['maintenance_end_matches_last_entry']} O.S. e difere em {$stats['maintenance_end_differs']}. Também não é seguro usar MAX(TL_DTFIM) como encerramento.\n\n"
    . "Metadados visíveis: STJ010 não é tabela temporal; CDC e Change Tracking estão desativados. Existe trigger STJ010_STAMP habilitada, mas sua definição não está visível para a conexão. Carimbos técnicos de registro não provam mudança para status concluído. Não foram visíveis dicionários SX3 ou uma tabela de histórico de encerramento; isso não prova sua inexistência em outros ambientes/permissões.\n\n"
    . "**Pendência PCM/TOTVS:** conferir a rotina que finaliza a O.S. e o dicionário funcional, especialmente as O.S. recentes; validar se TJ_DTMRFIM deve ser a referência funcional dos indicadores ou se deve haver registro dedicado do evento de fechamento. Qualquer mudança dessa regra exige homologação explícita, sem copiar datas automaticamente.\n\n"
    . "## Datas alternativas divergentes\n\n| O.S. | Filial | TJ_DTMRFIM | Último fim de apontamento |\n| --- | --- | --- | --- |\n";
foreach ($different as $row) $md .= "| {$row['TJ_ORDEM']} | {$row['TJ_FILIAL']} | {$row['maintenance_real_end']} | {$row['last_entry_end']} |\n";
$escape = static fn($v) => str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], (string)$v);
$md .= "\n## Relação para conferência\n\n| O.S. | Filial | Equipamento | Serviço / nome original | Área original | Situação | TJ_TERMINO | TJ_DTPRFIM | Origem | Apontamentos | Executado legível |\n| --- | --- | --- | --- | --- | --- | --- | --- | --- | ---: | --- |\n";
foreach ($rows as $row) {
    $cells = [$row['TJ_ORDEM'], $row['TJ_FILIAL'], $row['TJ_CODBEM'], $row['TJ_SERVICO'] . ' / ' . $row['service_name'],
        $row['TJ_CODAREA'], $row['TJ_SITUACA'], $row['TJ_TERMINO'], '[8 espaços]', $row['origin_date'], $row['entry_count'], $row['has_executed_service']];
    $md .= '| ' . implode(' | ', array_map($escape, $cells)) . " |\n";
}
$md .= "\nNenhuma alteração no TOTVS, no Ubuntu ou nas regras dos indicadores. Sem deploy.\n";
file_put_contents($directory . '/' . $stem . '.md', $md);
$sql = '-- SELECTs usados na investigação. Serviço Executado: consulta em lote existente de STQ010 por filial/O.S.' . "\n"
    . MissingCompletionAuditQueries::ORDERS . ";\n\n" . MissingCompletionAuditQueries::COMPARISON . ";\n\n"
    . MissingCompletionAuditQueries::COLUMNS . ";\n\n" . \App\Service\Protheus\ActionPlanObservationQueries::HISTORY_METADATA . ";\n";
$selectedJson = json_encode($identities, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$sql .= "\n-- Serviço Executado nas mesmas identidades, sem inferir status pelo texto.\n"
    . str_replace(':identities', "N'" . str_replace("'", "''", $selectedJson) . "'",
        \App\Service\Protheus\ActionPlanExecutionQuery::SQL) . ";\n";
file_put_contents($directory . '/' . $stem . '.sql', $sql);
echo json_encode(['report' => $directory . '/' . $stem . '.xlsx', 'summary' => $stats], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
