<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Credential;
use App\Ai\HuggingFaceHub;
use App\Ai\HuggingFaceModelAddResult;
use App\Ai\HuggingFaceModelRegistrar;
use App\Entity\HuggingFaceModel;
use App\Form\Model\HuggingFaceModelData;
use App\Form\Model\HuggingFaceSearch;
use App\Repository\HuggingFaceModelRepository;
use App\Routing\Requirements;
use App\Security\ApiKeyStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;

/** Discovery of Hugging Face models on the Hub and the list of models an admin added for runs and the judge. */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/llm-configuration/huggingface/models')]
final class HuggingFaceModelController extends AbstractController
{
    public const CSRF_ADD = 'add-huggingface-model';
    public const CSRF_DELETE = 'delete-huggingface-model-';

    #[Route('', name: 'app_admin_huggingface_models', methods: ['GET'])]
    public function discover(
        HuggingFaceHub $hub,
        HuggingFaceModelRepository $models,
        ApiKeyStore $keys,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        HuggingFaceSearch $search = new HuggingFaceSearch(),
    ): Response {
        $results = [];
        $error = null;
        try {
            $results = $hub->search($search->search, $search->task, $search->provider, $search->warm);
        } catch (HttpClientException|\RuntimeException $exception) {
            $error = 'The Hugging Face Hub could not be reached or refused the request.';
        }

        return $this->render('admin/huggingface_models.html.twig', [
            'search' => $search,
            'results' => $results,
            'error' => $error,
            'added' => $models->modelIds(),
            'hasToken' => $keys->has(Credential::HuggingFace),
            'providers' => HuggingFaceHub::inferenceProviders(),
        ]);
    }

    #[Route('', name: 'app_admin_huggingface_model_add', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF_ADD)]
    public function add(
        Request $request,
        HuggingFaceModelRegistrar $registrar,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        HuggingFaceModelData $data,
    ): Response {
        match ($registrar->add($data->modelId)) {
            HuggingFaceModelAddResult::AlreadyAdded => $this->addFlash('error', 'This model was already added.'),
            HuggingFaceModelAddResult::NotFoundOnHub, HuggingFaceModelAddResult::HubUnavailable => $this->addFlash('error', 'The Hub does not know this model, or could not be reached.'),
            HuggingFaceModelAddResult::Conflict => $this->addFlash('error', ConflictMessage::TEXT),
            HuggingFaceModelAddResult::Added => $this->addFlash('success', \sprintf('Model "%s" added.', $data->modelId)),
        };

        // the query string of the search is kept, so the admin lands on the same results
        return $this->redirectToRoute('app_admin_huggingface_models', $request->query->all());
    }

    #[Route('/{id:model}/delete', name: 'app_admin_huggingface_model_delete', requirements: ['id' => Requirements::ID], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"'.self::CSRF_DELETE.'" ~ args["model"].getId()'), methods: 'POST')]
    public function delete(HuggingFaceModel $model, EntityManagerInterface $entityManager): Response
    {
        $entityManager->remove($model);
        $entityManager->flush();
        $this->addFlash('success', \sprintf('Model "%s" removed. Runs keep their stored configuration.', $model->getModelId()));

        return $this->redirectToRoute('app_admin_llm_configuration');
    }
}
