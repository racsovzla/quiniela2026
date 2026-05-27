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
        if ($request->isSecure()) {
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