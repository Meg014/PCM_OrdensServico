<?php
declare(strict_types=1);

namespace App\Service\Import;

use Cake\I18n\Date;
use Cake\I18n\DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

final class TotvsValueNormalizer
{
    public function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = preg_replace('/[\x{00A0}\x{2007}\x{202F}\x{200B}\x{FEFF}]/u', ' ', (string)$value) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return $text === '' ? null : $text;
    }

    public function code(mixed $value): ?string
    {
        if (is_float($value) && floor($value) === $value) {
            return (string)(int)$value;
        }
        if ($value === null) {
            return null;
        }
        $code = preg_replace('/[\x{00A0}\x{2007}\x{202F}\x{200B}\x{FEFF}]/u', ' ', (string)$value) ?? '';
        $code = trim($code);

        return $code === '' ? null : $code;
    }

    public function decimal(mixed $value): ?string
    {
        if ($value === null || $this->text($value) === null) {
            return null;
        }
        if (is_numeric($value)) {
            return (string)$value;
        }
        $normalized = str_replace(['.', ','], ['', '.'], (string)$value);

        return is_numeric($normalized) ? $normalized : null;
    }

    public function date(mixed $value): ?Date
    {
        $dateTime = $this->dateTimeValue($value);

        return $dateTime ? new Date($dateTime) : null;
    }

    public function combineDateTime(mixed $dateValue, mixed $timeValue): ?DateTime
    {
        $date = $this->dateTimeValue($dateValue);
        if (!$date) {
            return null;
        }
        $time = $this->dateTimeValue($timeValue, true);
        if ($time) {
            $date = $date->setTime((int)$time->format('H'), (int)$time->format('i'), (int)$time->format('s'));
        } else {
            $date = $date->setTime(0, 0);
        }

        return new DateTime($date);
    }

    public function control(mixed $value): string
    {
        $text = mb_strtolower($this->text($value) ?? '', 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return trim($ascii === false ? $text : $ascii);
    }

    private function dateTimeValue(mixed $value, bool $allowTimeOnly = false): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_numeric($value)) {
            try {
                return DateTimeImmutable::createFromMutable(ExcelDate::excelToDateTimeObject((float)$value));
            } catch (Throwable) {
                return null;
            }
        }
        $text = $this->text($value);
        if ($text === null || in_array($text, [':', '/', '/ /', '/  /', '0'], true)) {
            return null;
        }
        $formats = $allowTimeOnly ? ['!H:i:s', '!H:i'] : ['!d/m/Y', '!Y-m-d', '!d/m/Y H:i:s', '!Y-m-d H:i:s'];
        foreach ($formats as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $text);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $parsed;
            }
        }

        return null;
    }
}
