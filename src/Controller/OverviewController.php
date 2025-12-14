<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OverviewController extends BaseController
{
    public const CONTROLLER_NAME = 'OverviewController';

    #[Route('/descrizione/overview', name: 'app_overview', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('overview/index.html.twig', [
            'controller_name' => self::CONTROLLER_NAME,
        ]);
    }
}
