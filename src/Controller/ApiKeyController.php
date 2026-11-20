<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Provider;
use App\Form\ApiKeyType;
use App\Security\ApiKeyStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class ApiKeyController extends AbstractController
{
    #[Route('/admin/api-keys', name: 'app_admin_api_keys', methods: ['GET', 'POST'])]
    public function index(Request $request, ApiKeyStore $store, FormFactoryInterface $formFactory): Response
    {
        $stored = $store->findAll();

        $rows = [];
        $status = Response::HTTP_OK;
        foreach (Provider::cases() as $provider) {
            $form = $formFactory->createNamed('key_'.$provider->value, ApiKeyType::class);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                /** @var array{apiKey: string} $data */
                $data = $form->getData();
                $store->save($provider, $data['apiKey']);
                $this->addFlash('success', \sprintf('%s saved.', $provider->label()));

                return $this->redirectToRoute('app_admin_api_keys');
            }
            if ($form->isSubmitted()) {
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }

            $rows[] = ['provider' => $provider, 'key' => $stored[$provider->value] ?? null, 'form' => $form->createView()];
        }

        return $this->render('admin/api_keys.html.twig', ['rows' => $rows], new Response(status: $status));
    }

    #[Route('/admin/api-keys/{provider}/delete', name: 'app_admin_api_key_delete', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-api-key-" ~ args["provider"].value'), methods: 'POST')]
    public function delete(Provider $provider, ApiKeyStore $store): Response
    {
        if ($store->delete($provider)) {
            $this->addFlash('success', \sprintf('%s deleted.', $provider->label()));
        }

        return $this->redirectToRoute('app_admin_api_keys');
    }
}
