<?php

namespace App\Controller;

use App\Rag\EmbeddingSchemaInspector;
use App\Rag\RagProfileManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RagProfileController extends BaseController
{
    public const CONTROLLER_NAME = 'RagProfileController';

    public function __construct(
        private readonly RagProfileManager $profiles,
        private readonly EmbeddingSchemaInspector $schemaInspector,
    ) {}

    #[Route('/status/rag-profiles', name: 'app_rag_profiles', methods: ['GET'])]
    public function index(): Response
    {
        $activeProfile = $this->profiles->getActiveProfile();

        return $this->render('rag/profiles.html.twig', [
            'controller_name'    => self::CONTROLLER_NAME,
            'active_profile'     => $activeProfile,
            'active_profile_name'=> $this->profiles->getActiveProfileName(),
            'available_profiles' => $this->profiles->listProfiles(),
            'schema_embedding_dimension' => $this->schemaInspector->getSchemaDimension(),
            'profile_embedding_dimension'=> (int) ($activeProfile['ai']['embed_dimension'] ?? 0),
            'schema_profile_aligned'     => $this->schemaInspector->isAlignedWithProfile($activeProfile),
        ]);
    }

    #[Route('/status/rag-tuning/profile', name: 'app_rag_profile_switch', methods: ['POST'])]
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

            $active = $this->profiles->getActiveProfile();
            if (!$this->schemaInspector->isAlignedWithProfile($active)) {
                $currentDimension = $this->schemaInspector->getSchemaDimension();
                $targetDimension  = (int) ($active['ai']['embed_dimension'] ?? 0);
                $commandReset = 'php bin/console app:reset-rag-schema --force';
                $commandReindex = 'php bin/console app:index-docs --force-reindex';

                $this->addFlash(
                    'warning',
                    sprintf(
                        '<div class="space-y-1">'
                        . 'Dimensione embedding schema (%s) diversa da quella richiesta dal profilo (%s).<br>'
                        . '<ol class="space-y-0.5">'
                        . '<li>Aggiorna DocumentChunk->embedding(%s)</li>'
                        . '<li>Esegui <code class="kbd kbd-sm">%s</code></li>'
                        . '<li>Esegui <code class="kbd kbd-sm">%s</code></li>'
                        . '</ol>' .
                        '</div>',
                        $currentDimension ?? 'n/d',
                        $targetDimension > 0 ? $targetDimension : 'n/d',
                        $targetDimension > 0 ? $targetDimension : 'n/d',
                        $commandReset,
                        $commandReindex
                    )
                );
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Errore nel salvataggio del profilo: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_rag_profiles');
    }
}
