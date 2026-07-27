<?php

namespace App\Security;

use App\Entity\Host;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private ApiTokenRepository $tokenRepo,
        private EntityManagerInterface $em,
    ) {}

    public function supports(Request $request): ?bool
    {
        $auth = $request->headers->get('Authorization', '');
        return str_starts_with($auth, 'Bearer ') || str_starts_with($auth, 'Basic ');
    }

    public function authenticate(Request $request): Passport
    {
        $auth = $request->headers->get('Authorization', '');

        if (str_starts_with($auth, 'Basic ')) {
            $decoded = base64_decode(substr($auth, 6), strict: true);
            $raw = $decoded !== false ? (explode(':', $decoded, 2)[1] ?? '') : '';
        } else {
            $raw = substr($auth, 7);
        }

        if ($raw === '') {
            throw new CustomUserMessageAuthenticationException('No token provided.');
        }

        $token = $this->tokenRepo->findByTokenHash(hash('sha256', $raw));

        if (!$token) {
            throw new CustomUserMessageAuthenticationException('Invalid token.');
        }

        if ($token->isExpired()) {
            throw new CustomUserMessageAuthenticationException('Token has expired.');
        }

        $route = $request->attributes->get('_route', '');

        if ($token->isHostScoped()) {
            // Host-scoped tokens are restricted to /api/self endpoints — no allowedRoutes check needed
            if (!str_starts_with($route, 'api_self_')) {
                throw new CustomUserMessageAuthenticationException('Host-scoped tokens may only access /api/self endpoints.');
            }
            // IP is validated against the host's live addresses instead of stored CIDRs
            $hostIps = $this->collectHostIps($token->getHost());
            if (!in_array($request->getClientIp(), $hostIps, true)) {
                throw new CustomUserMessageAuthenticationException('Token not permitted from this IP address.');
            }
        } else {
            if (!$token->isAllowedOnRoute($route)) {
                throw new CustomUserMessageAuthenticationException('Token not permitted for this endpoint.');
            }
            if (!$token->isAllowedFromIp((string) $request->getClientIp())) {
                throw new CustomUserMessageAuthenticationException('Token not permitted from this IP address.');
            }
        }

        $request->attributes->set('_api_token', $token);

        $identifier = $token->isHostScoped()
            ? 'host_' . $token->getHost()->getName()
            : 'token_' . $token->getName();

        return new SelfValidatingPassport(
            new UserBadge($identifier, fn(string $id) => new SamlUser($id))
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $apiToken = $request->attributes->get('_api_token');
        if ($apiToken) {
            $apiToken->setLastUsedAt(new \DateTimeImmutable());
            $this->em->flush();
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(['error' => $exception->getMessageKey()], Response::HTTP_UNAUTHORIZED);
    }

    private function collectHostIps(Host $host): array
    {
        $ips = [];
        foreach ($host->getInterfaces() as $iface) {
            if ($iface->isDeleted()) {
                continue;
            }
            if ($iface->getIpAddress()?->getAddress()) {
                $ips[] = $iface->getIpAddress()->getAddress();
            }
            if ($iface->getIpv6Address()?->getAddress()) {
                $ips[] = $iface->getIpv6Address()->getAddress();
            }
        }
        return $ips;
    }
}
