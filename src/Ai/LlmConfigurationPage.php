<?php

declare(strict_types=1);

namespace App\Ai;

use App\Controller\ConflictMessage;
use App\Form\ApiKeyType;
use App\Form\JudgeConfigurationType;
use App\Security\ApiKeyStore;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Builds the forms of the LLM configuration page (judge and one API key form per credential) and handles their submits. */
final readonly class LlmConfigurationPage
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private ApiKeyStore $keys,
        private JudgeSettingStore $judgeStore,
        private ModelCatalog $catalog,
    ) {
    }

    public function handle(Request $request): LlmConfigurationPageResult
    {
        $stored = $this->keys->findAll();
        $judge = $this->judgeStore->get();

        $judgeForm = $this->formFactory->createNamed('judge', JudgeConfigurationType::class, ['model' => null === $judge ? null : LlmConfiguration::choiceValue($judge->provider, $judge->model)]);
        $judgeForm->handleRequest($request);

        $status = Response::HTTP_OK;
        if ($judgeForm->isSubmitted() && $judgeForm->isValid()) {
            /** @var array{model: string} $data */
            $data = $judgeForm->getData();
            $configuration = $this->catalog->fromChoice($data['model'], null);

            if (null !== $configuration) {
                return $this->judgeStore->save($configuration)
                    ? LlmConfigurationPageResult::redirect('success', 'LLM judge saved.')
                    : LlmConfigurationPageResult::redirect('error', ConflictMessage::TEXT);
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

        return LlmConfigurationPageResult::render($status, $cards, $judge, $judgeForm->createView());
    }
}
