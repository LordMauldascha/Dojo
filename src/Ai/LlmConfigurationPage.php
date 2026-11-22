<?php

declare(strict_types=1);

namespace App\Ai;

use App\Controller\ConflictMessage;
use App\Form\ApiKeyType;
use App\Form\JudgeConfigurationType;
use App\Security\ApiKeyStore;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Builds the forms of the LLM configuration page (judge, AI helper and one API key form per credential) and handles their submits. */
final readonly class LlmConfigurationPage
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private ApiKeyStore $keys,
        private JudgeSettingStore $judgeStore,
        private AiHelperSettingStore $helperStore,
        private ModelCatalog $catalog,
    ) {
    }

    public function handle(Request $request): LlmConfigurationPageResult
    {
        $stored = $this->keys->findAll();
        $judge = $this->judgeStore->get();
        $helper = $this->helperStore->get();

        $judgeForm = $this->modelForm($request, 'judge', $judge, $this->judgeStore, 'LLM judge');
        if ($judgeForm instanceof LlmConfigurationPageResult) {
            return $judgeForm;
        }
        $helperForm = $this->modelForm($request, 'helper', $helper, $this->helperStore, 'AI helper');
        if ($helperForm instanceof LlmConfigurationPageResult) {
            return $helperForm;
        }

        $status = $judgeForm->isSubmitted() || $helperForm->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        $cards = [];
        foreach (Provider::cases() as $provider) {
            $credentials = [];
            foreach ($provider->credentials() as $credential) {
                $form = $this->formFactory->createNamed('key_'.$credential->value, ApiKeyType::class);
                $form->handleRequest($request);

                if ($form->isSubmitted() && $form->isValid()) {
                    /** @var array{apiKey: string} $data */
                    $data = $form->getData();
                    $this->keys->save($credential, $data['apiKey']);

                    return LlmConfigurationPageResult::redirect('success', \sprintf('%s saved.', $credential->label()));
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

        return LlmConfigurationPageResult::render($status, $cards, $judge, $judgeForm->createView(), $helper, $helperForm->createView());
    }

    /**
     * The model select of the judge or the AI helper. Returns the redirect result once a valid choice is saved, else the form.
     *
     * @return FormInterface<array{model: string|null}>|LlmConfigurationPageResult
     */
    private function modelForm(Request $request, string $name, ?LlmConfiguration $current, JudgeSettingStore|AiHelperSettingStore $store, string $label): FormInterface|LlmConfigurationPageResult
    {
        $form = $this->formFactory->createNamed($name, JudgeConfigurationType::class, ['model' => null === $current ? null : LlmConfiguration::choiceValue($current->provider, $current->model)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{model: string} $data */
            $data = $form->getData();
            $configuration = $this->catalog->fromChoice($data['model'], null);

            if (null !== $configuration) {
                return $store->save($configuration)
                    ? LlmConfigurationPageResult::redirect('success', \sprintf('%s saved.', $label))
                    : LlmConfigurationPageResult::redirect('error', ConflictMessage::TEXT);
            }
            $form->get('model')->addError(new FormError('Unknown model.'));
        }

        return $form;
    }
}
