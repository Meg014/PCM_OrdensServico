<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\EntityInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Http\Cookie\Cookie;
use Cake\Http\ServerRequest;
use Cake\I18n\DateTime;

class TvDeviceService
{
    public const COOKIE = 'pcm_tv_device';

    /** Returns the secret once and persists only its hash. */
    public function issue(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $table = FactoryLocator::get('Table')->get('TvDevices');
        $device = $table->newEmptyEntity();
        $device->patch(['user_id' => $userId, 'token_hash' => hash('sha256', $token),
            'expires_at' => new DateTime('+90 days')]);
        $table->saveOrFail($device);

        return $token;
    }

    /** Validates device expiration and current account permissions. */
    public function resolve(string $token): ?EntityInterface
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            return null;
        }
        $table = FactoryLocator::get('Table')->get('TvDevices');
        $device = $table->find()->contain(['Users'])->where([
            'token_hash' => hash('sha256', $token), 'expires_at >' => new DateTime(),
            'Users.ativo' => true, 'Users.role' => 'TV',
        ])->first();
        if ($device === null) {
            return null;
        }
        // Update at most daily; continuous presentation requests extend device validity.
        if ($device->expires_at < new DateTime('+89 days')) {
            $device->expires_at = new DateTime('+90 days');
            $table->saveOrFail($device);
        }

        return $device->user;
    }

    /** Removes the current device credential. */
    public function revoke(string $token): void
    {
        FactoryLocator::get('Table')->get('TvDevices')->deleteAll(['token_hash' => hash('sha256', $token)]);
    }

    /** Builds the persistent, host-only browser cookie. */
    public function cookie(ServerRequest $request, string $token): Cookie
    {
        return new Cookie(
            self::COOKIE,
            $token,
            new DateTime('+90 days'),
            '/',
            '',
            $request->getUri()->getScheme() === 'https',
            true,
            'Lax',
        );
    }
}
