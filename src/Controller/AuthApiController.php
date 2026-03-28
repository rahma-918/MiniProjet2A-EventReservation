<?php

namespace App\Controller;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\PasskeyAuthService;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
class AuthApiController extends AbstractController
{
    public function __construct(
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenManagerInterface $refreshManager,
        private readonly RefreshTokenGeneratorInterface $refreshGenerator,
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepo
    ) {}

    // -------------------------------------------------------
    // ENREGISTREMENT PASSKEY — Options
    // -------------------------------------------------------
    #[Route('/register/options', name: 'api_auth_register_options', methods: ['POST'])]
    public function registerOptions(Request $request, PasskeyAuthService $passkeyService): JsonResponse
    {
        $data        = json_decode($request->getContent(), true);
        $email       = $data['email'] ?? null;
        $displayName = $data['displayName'] ?? null;

        if (!$email) {
            return $this->json(['error' => 'Email requis'], Response::HTTP_BAD_REQUEST);
        }

        // Récupérer ou créer l'utilisateur
        $user = $this->userRepo->findOneBy(['email' => $email]);
        if (!$user) {
            $user = new User();
            $user->setEmail($email);
            $user->setUsername($displayName ?? explode('@', $email)[0]);
            $user->setRoles(['ROLE_USER']);
            $this->em->persist($user);
            $this->em->flush();
        }

        try {
            $options = $passkeyService->getRegistrationOptions($user);
            return $this->json($options);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    // -------------------------------------------------------
    // ENREGISTREMENT PASSKEY — Vérification
    // -------------------------------------------------------
    #[Route('/register/verify', name: 'api_auth_register_verify', methods: ['POST'])]
    public function registerVerify(Request $request, PasskeyAuthService $passkeyService): JsonResponse
    {
        $data       = json_decode($request->getContent(), true);
        $email      = $data['email'] ?? null;
        $credential = $data['credential'] ?? null;

        if (!$email || !$credential) {
            return $this->json(['error' => 'Données manquantes'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepo->findOneBy(['email' => $email]);
        if (!$user) {
            return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $passkeyService->verifyRegistration(json_encode($credential), $user);

            $jwt          = $this->jwtManager->create($user);
            $refreshToken = $this->createRefreshToken($user);

            return $this->json([
                'success'       => true,
                'token'         => $jwt,
                'refresh_token' => $refreshToken,
                'user'          => [
                    'id'       => $user->getId(),
                    'email'    => $user->getEmail(),
                    'username' => $user->getUsername(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    // -------------------------------------------------------
    // CONNEXION PASSKEY — Options
    // -------------------------------------------------------
    #[Route('/login/options', name: 'api_auth_login_options', methods: ['POST'])]
    public function loginOptions(PasskeyAuthService $passkeyService): JsonResponse
    {
        try {
            return $this->json($passkeyService->getLoginOptions());
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    // -------------------------------------------------------
    // CONNEXION PASSKEY — Vérification
    // -------------------------------------------------------
    #[Route('/login/verify', name: 'api_auth_login_verify', methods: ['POST'])]
    public function loginVerify(Request $request, PasskeyAuthService $passkeyService): JsonResponse
    {
        $data       = json_decode($request->getContent(), true);
        $credential = $data['credential'] ?? null;

        if (!$credential) {
            return $this->json(['error' => 'Credential manquant'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $user         = $passkeyService->verifyLogin(json_encode($credential));
            $jwt          = $this->jwtManager->create($user);
            $refreshToken = $this->createRefreshToken($user);

            return $this->json([
                'success'       => true,
                'token'         => $jwt,
                'refresh_token' => $refreshToken,
                'user'          => [
                    'id'       => $user->getId(),
                    'email'    => $user->getEmail(),
                    'username' => $user->getUsername(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    // -------------------------------------------------------
    // Profil utilisateur connecté (JWT)
    // -------------------------------------------------------
    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'id'       => $user->getId(),
            'email'    => $user->getEmail(),
            'username' => $user->getUsername(),
            'roles'    => $user->getRoles(),
        ]);
    }

    // -------------------------------------------------------
    // Helper : créer un refresh token
    // -------------------------------------------------------
    private function createRefreshToken(User $user): string
    {
        $refreshToken = $this->refreshGenerator->createForUserWithTtl($user, 2592000);
        $this->refreshManager->save($refreshToken);
        return $refreshToken->getRefreshToken();
    }
}