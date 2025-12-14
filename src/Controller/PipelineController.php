<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PipelineController extends BaseController
{
    public const CONTROLLER_NAME = 'PipelineController';

    #[Route('/pipeline-architettura', name: 'app_pipeline_architecture', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('pipeline/architecture.html.twig', [
            'controller_name' => self::CONTROLLER_NAME,
        ]);
    }
}
