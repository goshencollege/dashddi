<?php

namespace App\EventSubscriber;

use App\Security\SamlSettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class SessionExpirySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly SamlSettings $samlSettings,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priority 6: runs after the firewall (8) but before the controller
        return [KernelEvents::REQUEST => ['onKernelRequest', 6]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $session   = $request->getSession();
        $expiresAt = $session->get('_session_expires_at');

        if ($expiresAt === null) {
            // Session predates the expiry feature — initialize it for authenticated users
            // so the timeout starts from their next page load rather than never firing.
            $token = $this->tokenStorage->getToken();
            if ($token?->getUser() !== null) {
                $lifetime = $this->samlSettings->getSessionLifetimeSeconds();
                $session->set('_session_lifetime', $lifetime);
                $session->set('_session_expires_at', time() + $lifetime);
            }
            return;
        }

        if (time() <= $expiresAt) {
            $lifetime = $session->get('_session_lifetime', 1800);
            $session->set('_session_expires_at', time() + $lifetime);
            return;
        }

        $session->invalidate();

        $isApiOrAjax = $request->isXmlHttpRequest()
            || str_starts_with($request->getPathInfo(), '/api/');

        if ($isApiOrAjax) {
            $event->setResponse(new JsonResponse(['error' => 'Session expired'], 401));
        } else {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('saml_login')));
        }
    }
}
