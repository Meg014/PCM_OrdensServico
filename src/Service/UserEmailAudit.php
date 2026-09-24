<?php
declare(strict_types=1);
namespace App\Service;

use Cake\Validation\Validation;

/** Read-only preflight shared by the CLI and the local-users migration. */
final class UserEmailAudit
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function inspect(iterable $users): array
    {
        $issues = [];
        $changes = [];
        $groups = [];
        $count = 0;
        foreach ($users as $user) {
            $count++;
            $id = (int)$user['id'];
            $raw = (string)($user['email'] ?? '');
            $email = self::normalize($raw);
            if ($email === '' || strlen($email) > 254 || !Validation::email($email)) {
                $issues[] = 'ID ' . $id . ': e-mail ausente ou inválido.';
                continue;
            }
            $groups[$email][] = $id;
            if ($raw !== $email) $changes[$id] = $email;
        }
        foreach ($groups as $ids) {
            if (count($ids) > 1) $issues[] = 'IDs ' . implode(', ', $ids) . ': mesmo e-mail após normalização (índice único existente, inclusive inativos).';
        }
        return compact('count', 'issues', 'changes');
    }
}
