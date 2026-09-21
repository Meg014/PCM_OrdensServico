<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\TvDeviceService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TvDeviceMiddleware implements MiddlewareInterface
{
    /** Restores TV identity before normal authentication and validates every TV request. */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $service = new TvDeviceService();
        $session = $request->getAttribute('session');
        $existing = $session->read('Auth');
        $token = $request->getCookie(TvDeviceService::COOKIE, '');
        $token = is_string($token) ? $token : '';
        $user = null;
        if (!$existing || ($existing['role'] ?? null) === 'TV') {
            $user = $service->resolve($token);
            if ($existing) {
                $session->delete('Auth');
            }
            if ($user !== null) {
                if (!$existing) {
                    $session->renew();
                }
                $session->write('Auth', $user);
            }
        }
        $response = $handler->handle($request);
        // Never overwrite the cookie issued by login or deleted by logout.
        if ($request->getParam('controller') !== 'Auth') {
            if ($user !== null) {
                $cookie = $service->cookie($request, $token);
                $response = $response->withAddedHeader('Set-Cookie', $cookie->toHeaderValue());
            } elseif ($token !== '' && !$existing) {
                $cookie = $service->cookie($request, '')->withExpired();
                $response = $response->withAddedHeader('Set-Cookie', $cookie->toHeaderValue());
            }
        }

        return $response;
    }
}
