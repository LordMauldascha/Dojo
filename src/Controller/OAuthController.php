<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ConsentType;
use App\OAuth\ClientRegistration;
use App\OAuth\ConsentListener;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * OAuth 2.1 endpoints around the league/oauth2-server-bundle routes (/authorize, /token), so MCP agents can connect:
 * discovery metadata, dynamic client registration and the consent page. The public endpoints sit on the firewall
 * without security (security.yaml); the consent page needs the signed-in user.
 */
final class OAuthController extends AbstractController
{
    public const SCOPE = 'mcp';
    public const CONSENT_ROUTE = 'app_oauth_consent';
    public const RESOURCE_METADATA_ROUTE = 'app_oauth_resource_metadata';

    #[Route('/.well-known/oauth-protected-resource', name: self::RESOURCE_METADATA_ROUTE, methods: ['GET'])]
    #[Route('/.well-known/oauth-protected-resource/mcp', name: 'app_oauth_resource_metadata_mcp', methods: ['GET'])]
    public function resourceMetadata(Request $request): JsonResponse
    {
        return new JsonResponse([
            'resource' => $this->generateUrl('_mcp_endpoint_default', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'authorization_servers' => [$request->getSchemeAndHttpHost()],
            'scopes_supported' => [self::SCOPE],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    #[Route('/.well-known/oauth-authorization-server', name: 'app_oauth_server_metadata', methods: ['GET'])]
    public function serverMetadata(Request $request): JsonResponse
    {
        return new JsonResponse([
            'issuer' => $request->getSchemeAndHttpHost(),
            'authorization_endpoint' => $this->generateUrl('oauth2_authorize', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'token_endpoint' => $this->generateUrl('oauth2_token', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'registration_endpoint' => $this->generateUrl('app_oauth_register', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'response_types_supported' => ['code'],
            'grant_types_supported' => [OAuth2Grants::AUTHORIZATION_CODE, OAuth2Grants::REFRESH_TOKEN],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [self::SCOPE],
        ]);
    }

    /**
     * Dynamic client registration (RFC 7591) for public clients on the user's own machine.
     * shortcut: registered clients are never cleaned up, add a command when the table grows.
     */
    #[Route('/oauth/register', name: 'app_oauth_register', methods: ['POST'])]
    public function register(
        Request $request,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        ClientRegistration $registration,
        ClientManagerInterface $clients,
        #[Target('oauthRegisterLimiter')]
        RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        if (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        $client = new Client($registration->clientName, bin2hex(random_bytes(16)), null);
        $redirectUris = [];
        foreach ($registration->redirectUris as $uri) {
            $redirectUris[] = new RedirectUri($uri);
        }
        $client->setRedirectUris(...$redirectUris);
        $client->setGrants(new Grant(OAuth2Grants::AUTHORIZATION_CODE), new Grant(OAuth2Grants::REFRESH_TOKEN));
        $client->setScopes(new Scope(self::SCOPE));
        $clients->save($client);

        return new JsonResponse([
            'client_id' => $client->getIdentifier(),
            'client_name' => $client->getName(),
            'redirect_uris' => $registration->redirectUris,
            'grant_types' => [OAuth2Grants::AUTHORIZATION_CODE, OAuth2Grants::REFRESH_TOKEN],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => self::SCOPE,
        ], Response::HTTP_CREATED);
    }

    /** Shown by the ConsentListener while an agent waits for the user's approval; the decision goes back to /authorize via the session. */
    #[IsGranted('ROLE_USER')]
    #[Route('/oauth/consent', name: self::CONSENT_ROUTE, methods: ['GET', 'POST'])]
    public function consent(Request $request, ClientManagerInterface $clients, #[CurrentUser] User $user): Response
    {
        $session = $request->getSession();
        $pending = $session->get(ConsentListener::PENDING);
        $client = \is_array($pending) && \is_string($pending['client'] ?? null) ? $clients->find($pending['client']) : null;
        if (null === $client || !\is_string($pending['return'] ?? null)) {
            $this->addFlash('error', 'No agent is waiting for approval.');

            return $this->redirectToRoute('app_home');
        }

        $form = $this->createForm(ConsentType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $approve = $form->get('approve');
            \assert($approve instanceof SubmitButton);
            $session->remove(ConsentListener::PENDING);
            $session->set(ConsentListener::DECISION, ['client' => $client->getIdentifier(), 'approved' => $approve->isClicked()]);

            return $this->redirect($pending['return']);
        }

        return $this->render('oauth/consent.html.twig', [
            'form' => $form,
            'client' => $client,
        ]);
    }
}
