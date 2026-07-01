<?php

namespace App\EventSubscriber;

use App\Message\SyslogMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class LoginAuditSubscriber
{
    public function __construct(
        private readonly Connection           $connection,
        private readonly RequestStack         $requestStack,
        private readonly MessageBusInterface  $bus,
    ) {}

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        // API token requests authenticate on every call — don't flood the log
        if ($this->requestStack->getCurrentRequest()?->attributes->has('_api_token')) {
            return;
        }

        $identifier = $event->getAuthenticatedToken()->getUserIdentifier();
        $ip         = $this->requestStack->getCurrentRequest()?->getClientIp();
        $now        = new \DateTimeImmutable();

        $this->connection->insert('activity_log', [
            'action'          => 'login',
            'entity_type'     => null,
            'entity_id'       => null,
            'entity_label'    => $identifier,
            'user_identifier' => $identifier,
            'ip_address'      => $ip,
            'changed_fields'  => null,
            'created_at'      => $now->format('Y-m-d H:i:s'),
        ]);

        $this->bus->dispatch(new SyslogMessage(
            action:         'login',
            entityType:     null,
            entityId:       null,
            entityLabel:    $identifier,
            userIdentifier: $identifier,
            ipAddress:      $ip,
            changedFields:  null,
            occurredAt:     $now,
        ));
    }
}
