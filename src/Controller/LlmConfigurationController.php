<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Credential;
use App\Ai\LlmConfigurationPage;
use App\Repository\HuggingFaceModelRepository;
use App\Security\ApiKeyStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/llm-configuration')]
final class LlmConfigurationController extends AbstractController
{
    public const CSRF_DELETE_KEY = 'delete-api-key-';

    #[Route('', name: 'app_admin_llm_configuration', methods: ['GET', 'POST'])]
    public function index(Request $request, LlmConfigurationPage $page, HuggingFaceModelRepository $huggingFaceModels): Response
    {
        $result = $page->handle($request);
        if (null !== $result->flash) {
            $this->addFlash($result->flash['type'], $result->flash['message']);

            return $this->redirectToRoute('app_admin_llm_configuration');
        }

        return $this->render('admin/llm_configuration.html.twig', ['cards' => $result->cards, 'judge' => $result->judge, 'judgeForm' => $result->judgeForm, 'helper' => $result->helper, 'helperForm' => $result->helperForm, 'huggingFaceModels' => $huggingFaceModels->findAllOrdered()], new Response(status: $result->status));
    }

    #[Route('/keys/{credential}/delete', name: 'app_admin_llm_configuration_key_delete', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"'.self::CSRF_DELETE_KEY.'" ~ args["credential"].value'), methods: 'POST')]
    public function delete(Credential $credential, ApiKeyStore $store): Response
    {
        if ($store->delete($credential)) {
            $this->addFlash('success', \sprintf('%s deleted.', $credential->label()));
        }

        return $this->redirectToRoute('app_admin_llm_configuration');
    }
}
