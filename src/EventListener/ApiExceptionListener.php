<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Every error under /api/ comes back as JSON with a real status code so a
 * load test can validate on status alone. Priority 0 keeps it behind the
 * security ExceptionListener (priority 1) so anonymous requests still hit the
 * JWT entry point and get a 401 instead of a 403.
 */
#[AsEventListener(event: 'kernel.exception', priority: 0)]
final class ApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $e = $event->getThrowable();
        $status = match (true) {
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            $e instanceof AuthenticationException => Response::HTTP_UNAUTHORIZED,
            $e instanceof AccessDeniedException => Response::HTTP_FORBIDDEN,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };

        $message = $status >= 500 ? 'Internal server error' : $e->getMessage();

        $event->setResponse(new JsonResponse([
            'error' => ['status' => $status, 'message' => $message],
        ], $status));
    }
}
