<?php
declare(strict_types=1);
namespace App\Mailer\Exception;

/** Safe to persist: never carries tokens, credentials, HTTP bodies or an underlying exception. */
final class NonconformityDeliveryException extends \RuntimeException
{
    public function __construct(string $reason, public readonly bool $retryable = false, public readonly bool $uncertain = false, public readonly int $retryAfter = 0, public readonly bool $haltBatch = false)
    {
        parent::__construct($reason);
    }
}
