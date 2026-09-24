<?php
declare(strict_types=1);
namespace App\Middleware;

use App\Service\UserEmailAudit;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class NormalizeLoginEmailMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $params = $request->getAttribute('params', []);
        if (($params['controller'] ?? null) === 'Auth' && ($params['action'] ?? null) === 'login' && $request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            if (is_array($body)) {
                $body['email'] = is_string($body['email'] ?? null) ? UserEmailAudit::normalize($body['email']) : '';
                $request = $request->withParsedBody($body);
            }
        }
        return $handler->handle($request);
    }
}
