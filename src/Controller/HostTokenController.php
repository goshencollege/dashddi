<?php

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\Host;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/hosts/{id}/token')]
class HostTokenController extends AbstractController
{
    #[Route('/generate', name: 'host_token_generate', methods: ['POST'])]
    public function generate(Request $request, Host $host, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('host_token_' . $host->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('host_show', ['id' => $host->getId()]);
        }

        // Remove existing token first so the unique host_id constraint doesn't conflict
        $existing = $host->getApiToken();
        if ($existing !== null) {
            $em->remove($existing);
            $em->flush();
            $em->refresh($host); // clear stale inverse-side reference after deletion
        }

        $raw   = bin2hex(random_bytes(32));
        $token = new ApiToken();
        $token->setName('Host token: ' . $host->getName());
        $token->setOwnerIdentifier($this->getUser()->getUserIdentifier());
        $token->setTokenHash(hash('sha256', $raw));
        $token->setHost($host);
        $token->setAllowedRoutes([]);
        $token->setAllowedCidrs([]);

        $em->persist($token);
        $em->flush();

        $request->getSession()->set('_host_token_raw_' . $host->getId(), $raw);
        return $this->redirectToRoute('host_show', ['id' => $host->getId()]);
    }

    #[Route('/revoke', name: 'host_token_revoke', methods: ['POST'])]
    public function revoke(Request $request, Host $host, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('host_token_' . $host->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('host_show', ['id' => $host->getId()]);
        }

        $token = $host->getApiToken();
        if ($token !== null) {
            $em->remove($token);
            $em->flush();
            $this->addFlash('success', 'Host API token revoked.');
        }

        return $this->redirectToRoute('host_show', ['id' => $host->getId()]);
    }
}
