<?php

namespace App\Security;

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
        return str_starts_with($request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $raw = substr($request->headers->get('Authorization', ''), 7);

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

        $request->attributes->set('_api_token', $token);

        return new SelfValidatingPassport(
            new UserBadge($token->getOwnerIdentifier(), fn(string $id) => new SamlUser($id))
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $apiToken = $request->attributes->get('_api_token');

        if ($apiToken && !$apiToken->isAllowedOnRoute($request->attributes->get('_route', ''))) {
            return new JsonResponse(['error' => 'Token not permitted for this endpoint.'], Response::HTTP_FORBIDDEN);
        }

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
}
