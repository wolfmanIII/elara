<?php

namespace App\Security;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

final class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private ApiTokenRepository $apiTokenRepository,
        private EntityManagerInterface $em
    ) {}
    public function supports(Request $request): ?bool
    {
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return false;
        }

        $auth = $request->headers->get('Authorization');

        return $auth !== null && $auth !== '';
    }

    public function authenticate(Request $request): Passport
    {
        $authHeader = $request->headers->get('Authorization');
        $token = null;

        if ($authHeader && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $token = trim($matches[1]);
        }

        if ($token === null || $token === '') {
            throw new AuthenticationException('Token mancante o malformato');
        }

        $apiToken = $this->apiTokenRepository->findValidToken($token);
        if (!$apiToken) {
            throw new AuthenticationException('Token API non valido o scaduto');
        }

        $apiToken->markUsed();
        $this->em->persist($apiToken);
        $this->em->flush();

        return new Passport(
            new UserBadge($apiToken->getUser()->getUserIdentifier(), fn() => $apiToken->getUser()),
            new CustomCredentials(static fn() => true, $token)
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?JsonResponse
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?JsonResponse
    {
        return new JsonResponse(['error' => 'Autenticazione API fallita'], JsonResponse::HTTP_UNAUTHORIZED);
    }
}
