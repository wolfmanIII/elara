<?php

namespace App\Controller;

use App\Rag\RagProfileManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RagTuningController extends BaseController
{
    public const CONTROLLER_NAME = 'RagTuningController';

    public function __construct(
        private readonly RagProfileManager $profiles,
    ) {}

    #[Route('/descrizione/rag-tuning', name: 'app_rag_tuning', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('rag/tuning.html.twig', [
            'controller_name'    => self::CONTROLLER_NAME,
            'active_profile'     => $this->profiles->getActiveProfile(),
            'active_profile_name'=> $this->profiles->getActiveProfileName(),
            'available_profiles' => $this->profiles->listProfiles(),
        ]);
    }
}
