<?php

namespace App\Controller;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/status/api-tokens')]
final class ApiTokenController extends BaseController
{
    public const CONTROLLER_NAME = 'ApiTokenController';

    public function __construct(
        private readonly ApiTokenRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'app_api_tokens', methods: ['GET'])]
    public function index(): Response
    {
        $tokens = $this->repository->findAllOrdered();
        $total = \count($tokens);
        $active = \count(array_filter($tokens, static fn(ApiToken $t) => $t->isActive()));
        $revoked = \count(array_filter($tokens, static fn(ApiToken $t) => $t->isRevoked()));

        $usageTotal = array_reduce(
            $tokens,
            static fn(int $carry, ApiToken $token) => $carry + $token->getUsageCount(),
            0
        );

        return $this->render('status/api_tokens.html.twig', [
            'controller_name' => self::CONTROLLER_NAME,
            'tokens' => $tokens,
            'summary' => [
                'total' => $total,
                'active' => $active,
                'revoked' => $revoked,
                'usage' => $usageTotal,
            ],
        ]);
    }

    #[Route('/{id}/revoke', name: 'app_api_tokens_revoke', methods: ['POST'])]
    public function revoke(ApiToken $apiToken, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('revoke_token_' . $apiToken->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF non valido.');
            return $this->redirectToRoute('app_api_tokens');
        }

        if ($apiToken->isRevoked()) {
            $this->addFlash('info', 'Il token è già revocato.');
            return $this->redirectToRoute('app_api_tokens');
        }

        $apiToken->revoke();
        $this->em->flush();

        $this->addFlash('success', 'Token revocato con successo.');

        return $this->redirectToRoute('app_api_tokens');
    }
}
