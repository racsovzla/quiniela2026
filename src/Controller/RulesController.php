<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RulesController extends AbstractController
{
    #[Route('/reglas', name: 'app_rules', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('rules/index.html.twig');
    }
}