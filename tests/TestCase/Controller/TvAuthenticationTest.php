<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\TvDeviceService;
use App\Test\TestCase\Support\PcmSnapshotFixture;
use Cake\Datasource\FactoryLocator;
use Cake\Http\ServerRequest;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\Uri;

class TvAuthenticationTest extends TestCase
{
    use IntegrationTestTrait;
    use PcmSnapshotFixture;

    private object $tv;
    private object $admin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        self::connection()->begin();
        self::seedValidatedSnapshot();
        $table = FactoryLocator::get('Table')->get('Users');
        foreach (['tv' => 'TV', 'admin' => 'ADMIN'] as $key => $role) {
            $this->{$key} = $table->newEntity(['nome' => $key, 'email' => $key . '@example.com',
                'password' => 'Test-password-2026', 'role' => $role, 'ativo' => true]);
            $table->saveOrFail($this->{$key});
        }
        $this->token = (new TvDeviceService())->issue($this->tv->id);
        $this->enableCsrfToken();
    }

    protected function tearDown(): void
    {
        self::connection()->rollback();
        parent::tearDown();
    }

    public function testLoginCreatesSecurePersistentDevice(): void
    {
        $this->post('/login', ['email' => 'tv@example.com', 'password' => 'Test-password-2026']);
        $this->assertRedirect('/pcm/apresentacao');
        $cookie = $this->_response->getCookieCollection()->get(TvDeviceService::COOKIE);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('Lax', $cookie->getSameSite()->value);
        $this->assertGreaterThan(time() + 86400 * 89, $cookie->getExpiresTimestamp());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cookie->getValue());
        $devices = FactoryLocator::get('Table')->get('TvDevices');
        $this->assertTrue($devices->exists(['token_hash' => hash('sha256', $cookie->getValue())]));
        $this->assertFalse($devices->exists(['token_hash' => $cookie->getValue()]));
    }

    public function testDeviceRestoresPresentationAndRefreshWithoutSession(): void
    {
        $this->cookie(TvDeviceService::COOKIE, $this->token);
        foreach (['/pcm/apresentacao', '/pcm/apresentacao/data', '/login'] as $url) {
            $this->session(['Auth' => null]);
            $this->get($url);
            if ($url === '/login') {
                $this->assertRedirect('/pcm/apresentacao');
            } else {
                $this->assertResponseOk();
            }
            $this->assertSession('TV', 'Auth.role');
            if ($url === '/pcm/apresentacao') {
                $this->assertResponseNotContains('data-presentation-exit');
                $this->assertResponseNotContains('data-exit-url');
                $this->assertResponseNotContains('href="/usuarios"');
                $this->assertResponseNotContains('href="/importacoes"');
                $this->assertResponseContains('Logout');
            }
        }
    }

    public function testTvCannotAccessOtherRoutesIncludingFallbackAliases(): void
    {
        $this->cookie(TvDeviceService::COOKIE, $this->token);
        foreach (
            ['/usuarios', '/usuarios/novo', '/users/index', '/pcm/setor/MECANI',
            '/importacoes', '/importacoes/manual', '/report-imports/index', '/pcm/os/1',
            '/pcm', '/', '/pcm/analises', '/pcm/ordens', '/pcm/current-version',
            '/pcm/presentation-data', '/pages/home'] as $url
        ) {
            $this->get($url);
            $this->assertResponseCode(403, $url);
        }
        $this->post('/usuarios/' . $this->tv->id . '/revogar-tv');
        $this->assertResponseCode(403);
    }

    public function testInvalidExpiredAndRevokedTokensCannotAuthenticateEvenWithSession(): void
    {
        $devices = FactoryLocator::get('Table')->get('TvDevices');
        foreach (['invalid', 'expired', 'revoked'] as $case) {
            $token = (new TvDeviceService())->issue($this->tv->id);
            if ($case === 'invalid') {
                $token = str_repeat('f', 64);
            } elseif ($case === 'expired') {
                $devices->updateAll(['expires_at' => new DateTime('-1 day')], ['token_hash' => hash('sha256', $token)]);
            } else {
                (new TvDeviceService())->revoke($token);
            }
            foreach ([null, $this->tv] as $session) {
                $this->session(['Auth' => $session]);
                $this->cookie(TvDeviceService::COOKIE, $token);
                $this->get('/pcm/apresentacao/data');
                $this->assertResponseCode(302, (string)$this->_response->getBody());
                $this->assertRedirect('/login');
                $this->assertSession(null, 'Auth');
            }
        }
    }

    public function testAdminRevocationInvalidatesActiveTvSession(): void
    {
        $this->session(['Auth' => $this->admin]);
        $this->post('/usuarios/' . $this->tv->id . '/revogar-tv');
        $this->assertRedirect('/usuarios');
        $this->session(['Auth' => $this->tv]);
        $this->cookie(TvDeviceService::COOKIE, $this->token);
        $this->get('/pcm/apresentacao');
        $this->assertRedirect('/login');
    }

    public function testDeactivationAndReactivationDoNotRestoreOldDevice(): void
    {
        $this->session(['Auth' => $this->admin]);
        $this->post('/usuarios/' . $this->tv->id . '/editar', ['ativo' => false]);
        $this->assertRedirect('/usuarios');
        $this->session(['Auth' => null]);
        $this->cookie(TvDeviceService::COOKIE, $this->token);
        $this->get('/pcm/apresentacao');
        $this->assertRedirect('/login');
        $this->post('/login', ['email' => 'tv@example.com', 'password' => 'Test-password-2026']);
        $this->assertSession(null, 'Auth');
        $this->session(['Auth' => $this->admin]);
        $this->post('/usuarios/' . $this->tv->id . '/editar', ['ativo' => true]);
        $this->assertRedirect('/usuarios');
        $this->session(['Auth' => null]);
        $this->get('/pcm/apresentacao');
        $this->assertRedirect('/login');
    }

    public function testLogoutRevokesDevice(): void
    {
        $this->cookie(TvDeviceService::COOKIE, $this->token);
        $this->post('/logout');
        $this->assertRedirect('/login');
        $this->assertNull((new TvDeviceService())->resolve($this->token));
        $this->session(['Auth' => null]);
        $this->get('/pcm/apresentacao');
        $this->assertRedirect('/login');
    }

    public function testAdminCanCreateTv(): void
    {
        $this->session(['Auth' => $this->admin]);
        $this->post('/usuarios/novo', ['nome' => 'TV PCM', 'email' => 'tv2@example.com',
            'password' => 'Test-password-2026', 'role' => 'TV', 'ativo' => true]);
        $this->assertRedirect('/usuarios');
        $this->assertTrue(FactoryLocator::get('Table')->get('Users')->exists(['email' => 'tv2@example.com', 'role' => 'TV']));
    }

    public function testHttpsCookieAndRenewalDuringContinuousUse(): void
    {
        $service = new TvDeviceService();
        $https = (new ServerRequest())->withUri(new Uri('https://localhost/pcm/apresentacao'));
        $this->assertTrue($service->cookie($https, $this->token)->isSecure());
        $devices = FactoryLocator::get('Table')->get('TvDevices');
        $devices->updateAll(['expires_at' => new DateTime('+1 day')], ['user_id' => $this->tv->id]);
        $this->cookie($service::COOKIE, $this->token);
        $this->get('/pcm/apresentacao/data');
        $this->assertResponseOk();
        $device = $devices->find()->where(['user_id' => $this->tv->id])->firstOrFail();
        $this->assertGreaterThan(new DateTime('+89 days'), $device->expires_at);
        $this->assertStringContainsString('pcm_tv_device=', $this->_response->getHeaderLine('Set-Cookie'));
    }

    public function testPasswordAndRoleChangesRevokeDevices(): void
    {
        $this->session(['Auth' => $this->admin]);
        $this->post('/usuarios/' . $this->tv->id . '/senha', ['password' => 'Changed-password-2026']);
        $this->assertRedirect('/usuarios');
        $service = new TvDeviceService();
        $this->assertNull($service->resolve($this->token));
        $token = $service->issue($this->tv->id);
        $this->post('/usuarios/' . $this->tv->id . '/editar', ['role' => 'USUARIO']);
        $this->assertRedirect('/usuarios');
        $this->assertNull($service->resolve($token));
        $this->assertFalse(FactoryLocator::get('Table')->get('TvDevices')->exists(['user_id' => $this->tv->id]));
    }

    public function testRevocationRequiresPostAndCsrf(): void
    {
        $this->session(['Auth' => $this->admin]);
        $this->get('/users/revoke-tv/' . $this->tv->id);
        $this->assertResponseCode(405);
        $this->_csrfToken = false;
        $this->post('/usuarios/' . $this->tv->id . '/revogar-tv');
        $this->assertResponseCode(403);
        $this->assertNotNull((new TvDeviceService())->resolve($this->token));
    }

    public function testNormalUsersDoNotReceivePersistentAuthentication(): void
    {
        $table = FactoryLocator::get('Table')->get('Users');
        $user = $table->newEntity(['nome' => 'Normal', 'email' => 'normal@example.com',
            'password' => 'Test-password-2026', 'role' => 'USUARIO', 'ativo' => true]);
        $table->saveOrFail($user);
        foreach ([$user, $this->admin] as $account) {
            $this->session(['Auth' => null]);
            $this->post('/login', ['email' => $account->email, 'password' => 'Test-password-2026']);
            $this->assertRedirect('/pcm');
            $this->assertFalse($this->_response->getCookieCollection()->has(TvDeviceService::COOKIE));
            $this->session(['Auth' => null]);
            $this->get('/pcm');
            $this->assertRedirect('/login');
        }
    }
}
