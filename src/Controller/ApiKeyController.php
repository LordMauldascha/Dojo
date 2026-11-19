<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Provider;
use App\Entity\ProviderApiKey;
use App\Form\ApiKeyType;
use App\Security\ApiKeyStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class ApiKeyController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiKeyStore $store,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[Route('/admin/api-keys', name: 'app_admin_api_keys', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $stored = [];
        foreach ($this->entityManager->getRepository(ProviderApiKey::class)->findAll() as $key) {
            $stored[$key->getProvider()->value] = $key;
        }

        $rows = [];
        foreach (Provider::cases() as $provider) {
            $form = $this->formFactory->createNamed('key_'.$provider->value, ApiKeyType::class);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                /** @var array{apiKey: string} $data */
                $data = $form->getData();
                $this->store->save($provider, $data['apiKey']);
                $this->addFlash('success', \sprintf('%s gespeichert.', $provider->label()));

                return $this->redirectToRoute('app_admin_api_keys');
            }

            $rows[] = ['provider' => $provider, 'key' => $stored[$provider->value] ?? null, 'form' => $form->createView()];
        }

        return $this->render('admin/api_keys.html.twig', ['rows' => $rows]);
    }

    #[Route('/admin/api-keys/{provider}/delete', name: 'app_admin_api_key_delete', methods: ['POST'])]
    public function delete(Provider $provider, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-api-key-'.$provider->value, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $key = $this->entityManager->getRepository(ProviderApiKey::class)->findOneBy(['provider' => $provider]);
        if (null !== $key) {
            $this->entityManager->remove($key);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('%s gelöscht.', $provider->label()));
        }

        return $this->redirectToRoute('app_admin_api_keys');
    }
}
