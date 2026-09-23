<?php
declare(strict_types=1);

namespace App\Service\Protheus\Presentation;

/** Normalized data only: never carry raw SQL rows or driver errors into a view. */
final readonly class OrderSupplement
{
    /**
     * Dates: YYYY-MM-DD; times: HH:MM[:SS]; decimals: strings (no inferred units).
     * null means unknown/unmapped, not zero. Lists preserve individual entries.
     *
     * @param list<array{professional: ?string, code: ?string, date: ?string,
     *   start_time: ?string, end_time: ?string, hours: ?string}> $labor
     * @param list<array{code: ?string, description: ?string, quantity: ?string,
     *   unit: ?string, used_date: ?string, used_time: ?string}> $materials
     */
    public function __construct(
        public string $number,
        public ?string $branch,
        public ?string $description = null,
        public array $labor = [],
        public array $materials = [],
    ) {
    }
}
