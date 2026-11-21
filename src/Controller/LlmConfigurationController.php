<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Credential;
use App\Ai\JudgeSettingStore;
use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use App\Form\ApiKeyType;
use App\Form\JudgeConfigurationType;
use App\Security\ApiKeyStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class LlmConfigurationController extends AbstractController
{
    #[Route('/admin/llm-configuration', name: 'app_admin_llm_configuration', methods: ['GET', 'POST'])]
    public function index(Request $request, ApiKeyStore $store, JudgeSettingStore $judgeStore, FormFactoryInterface $formFactory): Response
    {
        $stored = $store->findAll();
        $judge = $judgeStore->get();

        $judgeForm = $formFactory->createNamed('judge', JudgeConfigurationType::class, ['model' => null === $judge ? null : LlmConfiguration::choiceValue($judge->provider, $judge->model)]);
        $judgeForm->handleRequest($request);

        $status = Response::HTTP_OK;
        if ($judgeForm->isSubmitted() && $judgeForm->isValid()) {
            /** @var array{model: string} $data */
            $data = $judgeForm->getData();
            $configuration = LlmConfiguration::fromChoice($data['model'], null);

            if (null !== $configuration) {
                $judgeStore->save($configuration);
                $this->addFlash('success', 'LLM judge saved.');

                return $this->redirectToRoute('app_admin_llm_configuration');
            }
            $judgeForm->get('model')->addError(new FormError('Unknown model.'));
        }
        if ($judgeForm->isSubmitted()) {
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        $cards = [];
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

                    return $this->redirectToRoute('app_admin_llm_configuration');
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

        return $this->render('admin/llm_configuration.html.twig', ['cards' => $cards, 'judge' => $judge, 'judgeForm' => $judgeForm->createView()], new Response(status: $status));
    }

    #[Route('/admin/llm-configuration/keys/{credential}/delete', name: 'app_admin_llm_configuration_key_delete', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-api-key-" ~ args["credential"].value'), methods: 'POST')]
    public function delete(Credential $credential, ApiKeyStore $store): Response
    {
        if ($store->delete($credential)) {
            $this->addFlash('success', \sprintf('%s deleted.', $credential->label()));
        }

        return $this->redirectToRoute('app_admin_llm_configuration');
    }
}
