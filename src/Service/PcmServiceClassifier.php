<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Query\SelectQuery;
use Normalizer;

final class PcmServiceClassifier
{
    public const CARD_CLASSES = ['emergency' => 'EMERGENCIAL', 'scheduled' => 'PROGRAMADA',
        'offseason' => 'ENTRESSAFRA'];
    public const LABELS = [
        'EMERGENCIAL' => 'Corretivas Emergenciais',
        'PROGRAMADA' => 'Corretivas Programadas',
        'ENTRESSAFRA' => 'Entressafra',
        'OUTROS' => 'Outros',
    ];

    /** Classifies snapshot values without rewriting the TOTVS payload or dimension records. */
    public function classify(?string $code, ?string $name): string
    {
        $code = $this->normalize($code);
        if ($code === 'COREME') {
            return 'EMERGENCIAL';
        }
        if ($code === 'CORPRO') {
            return 'PROGRAMADA';
        }
        $name = $this->normalize($name);
        if (preg_match('/\bCORRETIVA\s+EMERGEN(?:C|G)IAL\b/', $name)) {
            return 'EMERGENCIAL';
        }
        if (preg_match('/\bCORRETIVA\s+PROGRAMADA\b/', $name)) {
            return 'PROGRAMADA';
        }

        return str_contains($name, 'ENTRESSAFRA') ? 'ENTRESSAFRA' : 'OUTROS';
    }

    /** Emergency/scheduled corrective maintenance are subclasses of Tipo Manut. COR. */
    public function classifySnapshot(?string $type, ?string $code, ?string $name): string
    {
        $classification = $this->classify($code, $name);
        if (
            in_array($classification, ['EMERGENCIAL', 'PROGRAMADA'], true)
            && PcmIndicatorService::maintenanceTypeKey($type) !== 'corrective'
        ) {
            return 'OUTROS';
        }

        return $classification;
    }

    /** Uses the same Unicode normalization for code and name fallbacks. */
    private function normalize(?string $value): string
    {
        $value = Normalizer::normalize($value ?? '', Normalizer::FORM_D);
        $value = preg_replace('/\p{Mn}+/u', '', $value ?: '') ?? '';

        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));
    }

    /** Filters by distinct service definitions, never by loading individual snapshots. */
    public function applyFilter(SelectQuery $query, string $classification): void
    {
        $definitions = (clone $query)->select(['maintenance_type', 'service_code', 'service_name'], true)
            ->distinct(['maintenance_type', 'service_code', 'service_name'])->disableHydration();
        $matches = [];
        foreach ($definitions as $row) {
            $resolved = $this->classifySnapshot(
                $row['maintenance_type'],
                $row['service_code'],
                $row['service_name'],
            );
            if ($resolved === $classification) {
                $matches[] = [
                    ($row['maintenance_type'] === null ? 'maintenance_type IS' : 'maintenance_type')
                        => $row['maintenance_type'],
                    $row['service_code'] === null ? 'service_code IS' : 'service_code' => $row['service_code'],
                    $row['service_name'] === null ? 'service_name IS' : 'service_name' => $row['service_name'],
                ];
            }
        }
        $query->where($matches === [] ? ['1 = 0'] : ['OR' => $matches]);
    }
}
