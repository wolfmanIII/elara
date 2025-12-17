<?php

namespace App\Controller;

use App\Rag\RagProfileManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RagProfileController extends BaseController
{
    public const CONTROLLER_NAME = 'RagProfileController';

    public function __construct(
        private readonly RagProfileManager $profiles,
    ) {}

    #[Route('/status/rag-profiles', name: 'app_rag_profiles', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('rag/profiles.html.twig', [
            'controller_name'    => self::CONTROLLER_NAME,
            'active_profile'     => $this->profiles->getActiveProfile(),
            'active_profile_name'=> $this->profiles->getActiveProfileName(),
            'available_profiles' => $this->profiles->listProfiles(),
        ]);
    }

    #[Route('/descrizione/rag-tuning/profile', name: 'app_rag_profile_switch', methods: ['POST'])]
    public function switchProfile(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('rag_profile_switch', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF non valido.');
            return $this->redirectToRoute('app_rag_profiles');
        }

        $profile = (string) $request->request->get('profile', '');
        if ($profile === '') {
            $this->addFlash('error', 'Seleziona un profilo valido.');
            return $this->redirectToRoute('app_rag_profiles');
        }

        try {
            $this->profiles->useProfile($profile);
            $this->addFlash('success', sprintf('Profilo RAG "%s" attivato.', $profile));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Errore nel salvataggio del profilo: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_rag_profiles');
    }
}
