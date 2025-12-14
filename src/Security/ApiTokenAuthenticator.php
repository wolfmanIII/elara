<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use App\Repository\ApiTokenRepository;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

final class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(private ApiTokenRepository $apiTokenRepository)
    {
    }
    public function supports(Request $request): ?bool
    {
        return str_starts_with($request->getPathInfo(), '/api/');
    }

    public function authenticate(Request $request): Passport
    {
        $token = $request->headers->get('Authorization');
        $token = $token ? trim(str_replace('Bearer', '', $token)) : null;

        if (!$token) {
            throw new AuthenticationException('Token mancante');
        }

        $apiToken = $this->apiTokenRepository->findValidToken($token);
        if (!$apiToken) {
            throw new AuthenticationException('Token API non valido o scaduto');
        }

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
