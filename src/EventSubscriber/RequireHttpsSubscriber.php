<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class RequireHttpsSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly string $appEnvironment)
    {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($this->appEnvironment !== 'prod') {
            return;
        }

        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $forwardedProto = strtolower(trim(explode(',', (string) $request->headers->get('x-forwarded-proto', ''))[0] ?? ''));

        // Render terminates TLS at the edge and forwards plain HTTP to the container.
        // Respect forwarded proto to avoid redirect loops in production.
        if ($request->isSecure() || $forwardedProto === 'https') {
            return;
        }

        // If the proxy header is missing, do not force a redirect. This avoids
        // loops on platforms where the app only sees internal HTTP traffic.
        if ($forwardedProto === '') {
            return;
        }

        $event->setResponse(new RedirectResponse(
            'https://'.$request->getHttpHost().$request->getRequestUri(),
            RedirectResponse::HTTP_TEMPORARY_REDIRECT,
        ));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }
}