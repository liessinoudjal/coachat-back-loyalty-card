<?php

namespace App\Controller;

use App\Service\LegalTermsVersionProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class LegalTermsController extends AbstractController
{
    public function __construct(
        private readonly LegalTermsVersionProvider $legalTermsVersionProvider,
    ) {
    }

    #[Route('/api/legal/terms/current-version', name: 'legal_terms_current_version', methods: ['GET'])]
    public function getCurrentVersion(): JsonResponse
    {
        return new JsonResponse([
            'accepted_terms_current_version' => $this->legalTermsVersionProvider->getCurrentVersion(),
        ]);
    }
}
