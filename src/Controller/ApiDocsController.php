<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ApiDocsController extends BaseController
{
    public const CONTROLLER_NAME = 'ApiDocsController';

    #[Route('/api/chat/docs', name: 'app_api_docs', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('api/chat_docs.html.twig', [
            'controller_name' => self::CONTROLLER_NAME,
        ]);
    }
}
