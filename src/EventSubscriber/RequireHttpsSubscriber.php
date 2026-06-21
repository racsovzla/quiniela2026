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
        $hasForwardedProto = $request->headers->has('x-forwarded-proto');

        if ($request->isSecure()) {
            return;
        }

        // On managed platforms (Hugging Face Spaces, etc.), TLS usually terminates
        // at the edge and proxy headers may not map 1:1 to the public scheme.
        // If a forwarded proto header exists, skip app-level HTTPS redirects.
        if ($hasForwardedProto) {
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