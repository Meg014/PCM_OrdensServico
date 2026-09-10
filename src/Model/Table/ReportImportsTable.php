<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class ReportImportsTable extends Table
{
    /**
     * Returns the most recently persisted successful import.
     *
     * report_date identifies the source report and is not the portfolio version:
     * multiple valid imports can carry the same (or an older) report date.
     */
    public function findLatestSuccessful(SelectQuery $query): SelectQuery
    {
        return $query
            ->where([$this->aliasField('status') => 'success'])
            ->orderBy([$this->aliasField('id') => 'DESC'])
            ->limit(1);
    }

    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('report_imports');
        $this->setPrimaryKey('id');
        $this->getSchema()->setColumnType('metadata', 'json');
        $this->addBehavior('Timestamp');
        $this->hasMany('WorkOrderSnapshots');
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator->notEmptyString('file_name')->notEmptyString('file_hash')->lengthBetween('file_hash', [64, 64])->date('report_date')->inList('status', ['processing', 'success', 'failed', 'rejected']);
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        return $rules->add($rules->isUnique(['file_hash']), ['errorField' => 'file_hash']);
    }
}
