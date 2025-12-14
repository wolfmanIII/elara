<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RagTuningController extends BaseController
{
    public const CONTROLLER_NAME = 'RagTuningController';

    #[Route('/descrizione/rag-tuning', name: 'app_rag_tuning', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('rag/tuning.html.twig', [
            'controller_name' => self::CONTROLLER_NAME,
        ]);
    }
}
