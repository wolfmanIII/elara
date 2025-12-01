<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;

abstract class BaseController extends AbstractController
{
    /**
     * Render con supporto a Turbo:
     * - 200 se form non sottomesso
     * - 422 se form sottomesso ma NON valido (altrimenti Turbo non mostra gli errori)
     */
    protected function renderTurbo(string $template, FormInterface $form, array $options = []): Response
    {
        $response = $this->render($template, $options);

        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY); // 422
        }

        return $response;
    }
}
