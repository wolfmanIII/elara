<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UserGuideController extends BaseController
{
    public const CONTROLLER_NAME = 'UserGuideController';

    #[Route('/guida-utente', name: 'app_user_guide', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('user/guide.html.twig', [
            'controller_name' => self::CONTROLLER_NAME,
        ]);
    }
}
