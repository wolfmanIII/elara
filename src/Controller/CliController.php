<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CliController extends BaseController
{
    public const CONTROLLER_NAME = 'CliController';

    #[Route('/descrizione/cli-operazioni', name: 'app_cli_operations', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('cli/operations.html.twig', [
            'controller_name' => self::CONTROLLER_NAME,
        ]);
    }
}
