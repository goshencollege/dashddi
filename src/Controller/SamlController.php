<?php

namespace App\Controller;

use App\Security\SamlSettings;
use App\Service\SamlReturnPathResolver;
use OneLogin\Saml2\Auth;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class SamlController extends AbstractController
{
    public function __construct(
        private readonly SamlSettings $samlSettings,
        private readonly SamlReturnPathResolver $returnPathResolver,
    ) {}

    /** Login page — shows "Sign in with Okta" button and any error flash messages. */
    #[Route('/saml/login', name: 'saml_login')]
    public function login(Request $request): Response
    {
        $returnTo = $this->returnPathResolver->sanitize($request->query->get('return_to'));

        if ($this->getUser()) {
            return $this->redirect($returnTo ?? $this->generateUrl('host_index'));
        }

        return $this->render('saml/login.html.twig', ['return_to' => $returnTo]);
    }

    /**
     * Called by the client-side idle timer once a tab's session should be expired.
     * Invalidates explicitly rather than relying on SessionExpirySubscriber, since
     * /saml/* is excluded there to stop visiting it from extending the idle timer.
     */
    #[Route('/saml/expire', name: 'saml_expire')]
    public function expire(Request $request, TokenStorageInterface $tokenStorage): Response
    {
        $returnTo = $this->returnPathResolver->sanitize($request->query->get('return_to'));

        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }
        $tokenStorage->setToken(null);

        return $this->redirectToRoute('saml_login', $returnTo !== null ? ['return_to' => $returnTo] : []);
    }

    /** Initiates the SAML SSO flow by redirecting to the IdP. */
    #[Route('/saml/initiate', name: 'saml_initiate')]
    public function initiate(Request $request): Response
    {
        $returnTo = $this->returnPathResolver->sanitize($request->query->get('return_to'));

        $auth = new Auth($this->samlSettings->toArray());
        $ssoUrl = $auth->login(returnTo: $returnTo, parameters: [], forceAuthn: false, isPassive: false, stay: true);

        return $this->redirect($ssoUrl);
    }

    /**
     * ACS endpoint — receives the IdP POST-back.
     * The SamlAuthenticator intercepts this before the action runs.
     */
    #[Route('/saml/acs', name: 'saml_acs', methods: ['POST'])]
    public function acs(): Response
    {
        throw new \LogicException('This route is handled by the SamlAuthenticator.');
    }

    /** Returns SP metadata XML for Okta to import. */
    #[Route('/saml/metadata', name: 'saml_metadata')]
    public function metadata(): Response
    {
        $auth     = new Auth($this->samlSettings->toArray());
        $settings = $auth->getSettings();
        $metadata = $settings->getSPMetadata();

        $errors = $settings->validateMetadata($metadata);
        if (!empty($errors)) {
            throw new \RuntimeException('SP metadata invalid: ' . implode(', ', $errors));
        }

        return new Response($metadata, Response::HTTP_OK, ['Content-Type' => 'application/xml']);
    }

    /** Logout is handled by the Symfony security firewall. */
    #[Route('/saml/logout', name: 'saml_logout')]
    public function logout(): never
    {
        throw new \LogicException('This route is handled by the security firewall.');
    }
}
