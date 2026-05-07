<?php

namespace App\Controller;

use App\Entity\ApiToken;
use App\Form\ApiTokenType;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api-tokens')]
class ApiTokenController extends AbstractController
{
    #[Route('', name: 'api_token_index', methods: ['GET'])]
    public function index(ApiTokenRepository $repo): Response
    {
        return $this->render('api_token/index.html.twig', [
            'tokens' => $repo->findByOwner($this->getUser()->getUserIdentifier()),
        ]);
    }

    #[Route('/new', name: 'api_token_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $token = new ApiToken();
        $form  = $this->createForm(ApiTokenType::class, $token);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $raw = bin2hex(random_bytes(32));
            $token->setTokenHash(hash('sha256', $raw));
            $token->setOwnerIdentifier($this->getUser()->getUserIdentifier());

            $em->persist($token);
            $em->flush();

            $request->getSession()->set('_new_api_token', $raw);
            return $this->redirectToRoute('api_token_created', ['id' => $token->getId()]);
        }

        return $this->render('api_token/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/created', name: 'api_token_created', methods: ['GET'])]
    public function created(ApiToken $token, Request $request): Response
    {
        $raw = $request->getSession()->get('_new_api_token');
        if (!$raw) {
            return $this->redirectToRoute('api_token_index');
        }
        $request->getSession()->remove('_new_api_token');

        return $this->render('api_token/created.html.twig', [
            'token' => $token,
            'raw'   => $raw,
        ]);
    }

    #[Route('/{id}/edit', name: 'api_token_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ApiToken $token, EntityManagerInterface $em): Response
    {
        if ($token->getOwnerIdentifier() !== $this->getUser()->getUserIdentifier()) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(ApiTokenType::class, $token);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Token "' . $token->getName() . '" updated.');
            return $this->redirectToRoute('api_token_index');
        }

        return $this->render('api_token/edit.html.twig', [
            'form'  => $form,
            'token' => $token,
        ]);
    }

    #[Route('/{id}/regenerate', name: 'api_token_regenerate', methods: ['POST'])]
    public function regenerate(Request $request, ApiToken $token, EntityManagerInterface $em): Response
    {
        if ($token->getOwnerIdentifier() !== $this->getUser()->getUserIdentifier()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('regenerate_api_token_' . $token->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('api_token_index');
        }

        $raw = bin2hex(random_bytes(32));
        $token->setTokenHash(hash('sha256', $raw));
        $em->flush();

        $request->getSession()->set('_new_api_token', $raw);
        return $this->redirectToRoute('api_token_created', ['id' => $token->getId()]);
    }

    #[Route('/{id}/delete', name: 'api_token_delete', methods: ['POST'])]
    public function delete(Request $request, ApiToken $token, EntityManagerInterface $em): Response
    {
        if ($token->getOwnerIdentifier() !== $this->getUser()->getUserIdentifier()) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('delete_api_token_' . $token->getId(), $request->request->get('_token'))) {
            $em->remove($token);
            $em->flush();
            $this->addFlash('success', 'API token "' . $token->getName() . '" revoked.');
        }

        return $this->redirectToRoute('api_token_index');
    }
}
