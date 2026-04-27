<?php

namespace App\Security;

class SamlSettings
{
    private array $settings;

    public function __construct(
        string $spEntityId,
        string $spAcsUrl,
        string $spSloUrl,
        string $spCert,
        string $spPrivateKey,
        string $idpEntityId,
        string $idpSsoUrl,
        string $idpCert,
        bool $debug,
    ) {
        $this->settings = [
            'strict' => true,
            'debug'  => $debug,
            'sp' => [
                'entityId' => $spEntityId,
                'assertionConsumerService' => [
                    'url'     => $spAcsUrl,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'singleLogoutService' => [
                    'url'     => $spSloUrl,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
                'x509cert'    => $spCert,
                'privateKey'  => $spPrivateKey,
            ],
            'idp' => [
                'entityId' => $idpEntityId,
                'singleSignOnService' => [
                    'url'     => $idpSsoUrl,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $idpCert,
            ],
        ];
    }

    public function toArray(): array
    {
        return $this->settings;
    }
}
