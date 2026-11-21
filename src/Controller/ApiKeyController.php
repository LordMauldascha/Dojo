<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Credential;
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

        $cards = [];
        $status = Response::HTTP_OK;
        foreach (Provider::cases() as $provider) {
            $credentials = [];
            foreach ($provider->credentials() as $credential) {
                $form = $formFactory->createNamed('key_'.$credential->value, ApiKeyType::class);
                $form->handleRequest($request);

                if ($form->isSubmitted() && $form->isValid()) {
                    /** @var array{apiKey: string} $data */
                    $data = $form->getData();
                    $store->save($credential, $data['apiKey']);
                    $this->addFlash('success', \sprintf('%s saved.', $credential->label()));

                    return $this->redirectToRoute('app_admin_api_keys');
                }
                if ($form->isSubmitted()) {
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                }

                $credentials[] = ['credential' => $credential, 'key' => $stored[$credential->value] ?? null, 'form' => $form->createView()];
            }

            $cards[] = [
                'provider' => $provider,
                'credentials' => $credentials,
                'configured' => array_any($credentials, static fn (array $row): bool => null !== $row['key']),
            ];
        }

        return $this->render('admin/api_keys.html.twig', ['cards' => $cards], new Response(status: $status));
    }

    #[Route('/admin/api-keys/{credential}/delete', name: 'app_admin_api_key_delete', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-api-key-" ~ args["credential"].value'), methods: 'POST')]
    public function delete(Credential $credential, ApiKeyStore $store): Response
    {
        if ($store->delete($credential)) {
            $this->addFlash('success', \sprintf('%s deleted.', $credential->label()));
        }

        return $this->redirectToRoute('app_admin_api_keys');
    }
}
