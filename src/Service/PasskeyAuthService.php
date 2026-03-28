<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use App\Repository\WebauthnCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

class PasskeyAuthService
{
    private string $rpId;
    private string $rpName;
    private string $origin;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly WebauthnCredentialRepository $credRepo,
        string $rpId = 'localhost',
        string $rpName = 'Eventix',
        string $origin = 'http://localhost:8080'
    ) {
        $this->rpId   = $rpId;
        $this->rpName = $rpName;
        $this->origin = $origin;
    }

    // -------------------------------------------------------
    // ENREGISTREMENT — Étape 1 : générer les options
    // -------------------------------------------------------
    public function getRegistrationOptions(User $user): array
    {
        $rp = PublicKeyCredentialRpEntity::create($this->rpName, $this->rpId);

        $userEntity = PublicKeyCredentialUserEntity::create(
            $user->getEmail(),
            (string) $user->getId(),
            $user->getEmail()
        );

        // Algorithmes supportés : ES256 et RS256
        $pubKeyCredParams = [
            PublicKeyCredentialParameters::create('public-key', -7),   // ES256
            PublicKeyCredentialParameters::create('public-key', -257), // RS256
        ];

        // Exclure les credentials déjà enregistrés
        $excludeCredentials = [];
        foreach ($user->getWebauthnCredentials() as $cred) {
            $excludeCredentials[] = PublicKeyCredentialDescriptor::create(
                'public-key',
                base64_decode($cred->getCredentialId())
            );
        }

        $challenge = random_bytes(32);

        $options = PublicKeyCredentialCreationOptions::create(
            rp: $rp,
            user: $userEntity,
            challenge: $challenge,
            pubKeyCredParams: $pubKeyCredParams,
            authenticatorSelection: null,
            attestation: 'none',
            excludeCredentials: $excludeCredentials,
            extensions: null,
            timeout: 60000
        );

        // Stocker en session pour la vérification
        $session = $this->requestStack->getSession();
        $session->set('webauthn_registration_options', serialize($options));

        return $this->serializeCreationOptions($options, $challenge);
    }

    // -------------------------------------------------------
    // ENREGISTREMENT — Étape 2 : vérifier la réponse
    // -------------------------------------------------------
    public function verifyRegistration(string $responseJson, User $user): void
    {
        $session = $this->requestStack->getSession();
        $serialized = $session->get('webauthn_registration_options');
        if (!$serialized) {
            throw new \RuntimeException('Options de registration introuvables en session.');
        }

        $data = json_decode($responseJson, true);
        if (!$data) {
            throw new \RuntimeException('Réponse JSON invalide.');
        }

        // Décoder les champs base64url
        $clientDataJSON    = $this->base64UrlDecode($data['response']['clientDataJSON']);
        $attestationObject = $this->base64UrlDecode($data['response']['attestationObject']);
        $credentialId      = $this->base64UrlDecode($data['id'] ?? $data['rawId']);

        // Vérifications basiques du clientDataJSON
        $clientData = json_decode($clientDataJSON, true);
        if ($clientData['type'] !== 'webauthn.create') {
            throw new \RuntimeException('Type de clientData invalide.');
        }
        if ($clientData['origin'] !== $this->origin) {
            throw new \RuntimeException('Origin invalide : ' . $clientData['origin']);
        }

        // Sauvegarder la credential (stockage simplifié)
        $webauthnCred = new WebauthnCredential();
        $webauthnCred->setUser($user);
        $webauthnCred->setCredentialId(base64_encode($credentialId));
        $webauthnCred->setCredentialSource(json_encode([
            'id'                => base64_encode($credentialId),
            'attestationObject' => base64_encode($attestationObject),
            'clientDataJSON'    => base64_encode($clientDataJSON),
            'registeredAt'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]));
        $webauthnCred->setName('Passkey - ' . date('d/m/Y'));

        $this->em->persist($webauthnCred);
        $this->em->flush();

        $session->remove('webauthn_registration_options');
    }

    // -------------------------------------------------------
    // CONNEXION — Étape 1 : générer les options
    // -------------------------------------------------------
    public function getLoginOptions(): array
    {
        $challenge = random_bytes(32);

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $this->rpId,
            allowCredentials: [],
            userVerification: 'preferred',
            timeout: 60000
        );

        $session = $this->requestStack->getSession();
        $session->set('webauthn_login_challenge', base64_encode($challenge));

        return [
            'challenge'        => $this->base64UrlEncode($challenge),
            'rpId'             => $this->rpId,
            'timeout'          => 60000,
            'userVerification' => 'preferred',
            'allowCredentials' => [],
        ];
    }

    // -------------------------------------------------------
    // CONNEXION — Étape 2 : vérifier la réponse
    // -------------------------------------------------------
    public function verifyLogin(string $responseJson): User
    {
        $session = $this->requestStack->getSession();
        $storedChallenge = $session->get('webauthn_login_challenge');
        if (!$storedChallenge) {
            throw new \RuntimeException('Challenge de login introuvable en session.');
        }

        $data = json_decode($responseJson, true);
        if (!$data) {
            throw new \RuntimeException('Réponse JSON invalide.');
        }

        // Décoder clientDataJSON
        $clientDataJSON = $this->base64UrlDecode($data['response']['clientDataJSON']);
        $clientData     = json_decode($clientDataJSON, true);

        if ($clientData['type'] !== 'webauthn.get') {
            throw new \RuntimeException('Type de clientData invalide.');
        }
        if ($clientData['origin'] !== $this->origin) {
            throw new \RuntimeException('Origin invalide.');
        }

        // Vérifier le challenge
        $receivedChallenge = $this->base64UrlDecode($clientData['challenge']);
        if (base64_encode($receivedChallenge) !== $storedChallenge) {
            throw new \RuntimeException('Challenge invalide.');
        }

        // Retrouver la credential en base
        $credentialId = base64_encode($this->base64UrlDecode($data['id'] ?? $data['rawId']));
        $webauthnCred = $this->credRepo->findByCredentialId($credentialId);
        if (!$webauthnCred) {
            throw new \RuntimeException('Credential introuvable.');
        }

        // Mettre à jour la date de dernière utilisation
        $webauthnCred->touch();
        $this->em->flush();

        $session->remove('webauthn_login_challenge');

        return $webauthnCred->getUser();
    }

    // -------------------------------------------------------
    // Helpers de sérialisation
    // -------------------------------------------------------
    private function serializeCreationOptions(PublicKeyCredentialCreationOptions $options, string $challenge): array
    {
        return [
            'challenge' => $this->base64UrlEncode($challenge),
            'rp' => [
                'name' => $this->rpName,
                'id'   => $this->rpId,
            ],
            'user' => [
                'id'          => $this->base64UrlEncode($options->user->id),
                'name'        => $options->user->name,
                'displayName' => $options->user->displayName,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'timeout'               => 60000,
            'attestation'           => 'none',
            'excludeCredentials'    => [],
            'authenticatorSelection' => [
                'userVerification' => 'preferred',
                'residentKey'      => 'preferred',
            ],
        ];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($data);
    }
}