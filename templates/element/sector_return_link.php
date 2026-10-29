<?php
$returnSector = $this->getRequest()->getQuery('return_sector');
$returnFilters = $this->getRequest()->getQuery('return_filters', []);
if (is_string($returnSector) && preg_match('/^[A-Z0-9_-]{1,30}$/D', $returnSector) && is_array($returnFilters)) {
    $returnFilters = array_intersect_key($returnFilters, array_flip([...\App\Service\Protheus\ProtheusSectorService::FILTERS, 'page', 'limit']));
    $returnFilters = array_filter($returnFilters, static fn ($v) => is_string($v) && strlen($v) <= 255);
    echo $this->Html->link('Voltar ao setor', ['_name' => 'pcm-sector', 'code' => $returnSector, '?' => $returnFilters], ['class' => 'btn btn-outline-secondary mb-3']);
}
?>
