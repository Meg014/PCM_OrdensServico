<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\EntityInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\Utility\Security;

/** Bearer links grant read access to one action, never a PCM session. */
final class NonconformityConsultationService
{
    public const BASE_URL = 'https://pcm.biolasa.com.br';
    public const PATH = '/consulta/nao-conformidades/';
    public const LIFETIME = 90 * 86400;

    public function link(EntityInterface $action, EntityInterface $responsible): string
    {
        $claims = ['v' => 1, 'a' => (int)$action->id, 'n' => (int)$action->nonconformity_id,
            'r' => (int)$responsible->id, 'rr' => (int)$responsible->revision, 'c' => (int)$action->assignment_cycle,
            'e' => hash('sha256', (string)$responsible->email), 'exp' => time() + self::LIFETIME,
            'nonce' => bin2hex(random_bytes(16))];
        $body = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        return self::BASE_URL . self::PATH . $body . '.' . $this->signature($body);
    }

    private function signature(string $body): string
    {
        return hash_hmac('sha256', 'nc-consultation-v1|' . $body, Security::getSalt());
    }

    /** Always check current ownership; old links stop working after reassignment/email changes. */
    public function resolve(string $token): array
    {
        $invalid = static fn () => new NotFoundException('Link de consulta inválido ou expirado. Solicite um novo aviso à Qualidade.');
        if (strlen($token) > 1024 || !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $parts)
            || !hash_equals($this->signature($parts[1]), $parts[2])) throw $invalid();
        $claims = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        if (!is_array($claims) || ($claims['v'] ?? null) !== 1 || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time()) throw $invalid();
        foreach (['a', 'n', 'r', 'c', 'rr'] as $key) if (!is_int($claims[$key] ?? null) || $claims[$key] < (in_array($key, ['c', 'rr'], true) ? 0 : 1)) throw $invalid();
        $nc = new NonconformityService();
        $action = $nc->table('NonconformityActions')->find()->where(['id' => $claims['a'], 'nonconformity_id' => $claims['n'],
            'responsible_id' => $claims['r'], 'assignment_cycle' => $claims['c']])->first();
        $responsible = $nc->table('NonconformityResponsibles')->find()->where(['id' => $claims['r'], 'active' => true])->first();
        if (!$action || !$responsible || (int)$responsible->revision !== $claims['rr'] || !is_string($claims['e'] ?? null)
            || !hash_equals(hash('sha256', (string)$responsible->email), $claims['e'])) throw $invalid();
        $record = $nc->table('Nonconformities')->get($claims['n']);
        return compact('record', 'action', 'responsible');
    }
}
