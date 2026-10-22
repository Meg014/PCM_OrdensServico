<?php
declare(strict_types=1);

namespace App\Service\Protheus;

final class EquipmentFilter
{
    public static function normalize(array $filters): array
    {
        // Keep legacy links working; selecting a suggestion takes precedence over its label.
        if (($filters['bem'] ?? '') !== '' && array_key_exists('bem_filial', $filters) && $filters['bem_filial'] !== '') {
            if (($filters['filial'] ?? '') !== '' && $filters['filial'] !== $filters['bem_filial']) {
                throw new \InvalidArgumentException('O equipamento selecionado pertence a outra filial.');
            }
        }
        if (($filters['equipment_q'] ?? '') !== '') {
            $filters['nome_bem'] = ($filters['bem'] ?? '') === '' ? $filters['equipment_q'] : '';
        }

        return $filters;
    }

    public static function branch(array $filters): string
    {
        return ($filters['bem'] ?? '') !== '' && ($filters['bem_filial'] ?? '') !== ''
            ? $filters['bem_filial'] : ($filters['filial'] ?? '');
    }
}
