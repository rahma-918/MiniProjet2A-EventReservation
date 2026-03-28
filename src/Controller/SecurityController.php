<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegisterType;
use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }
        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error'         => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request                     $request,
        UserPasswordHasherInterface $hasher,
        UserRepository              $userRepository
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $form = $this->createForm(RegisterType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data     = $form->getData();
            $existing = $userRepository->findOneBy(['email' => $data['email']]);
            if ($existing) {
                $this->addFlash('error', 'Cet email est déjà utilisé.');
                return $this->render('security/register.html.twig', ['form' => $form]);
            }
            $user = new User();
            $user->setEmail($data['email']);
            $user->setUsername($data['username']);
            $user->setPassword($hasher->hashPassword($user, $data['password']));
            $user->setRoles(['ROLE_USER']);
            $userRepository->save($user);
            $this->addFlash('success', 'Compte créé ! Connectez-vous.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/register.html.twig', ['form' => $form]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Intercepté par le firewall Symfony.');
    }

    // ─────────────────────────────────────────────────────────
    // SESSION-FROM-JWT
    //
    // Crée une session Symfony à partir d'un JWT.
    // Appelé par auth.js après inscription ou connexion Passkey.
    //
    // CORRECTIONS :
    // 1. LexikJWT met l'email dans le champ "username" du payload
    //    (car getUserIdentifier() retourne l'email dans User.php)
    // 2. On cherche l'utilisateur par email dans les deux champs
    // 3. Log détaillé pour faciliter le debug
    // ─────────────────────────────────────────────────────────
    #[Route('/auth/session-from-jwt', name: 'auth_session_from_jwt', methods: ['POST'])]
    public function sessionFromJwt(
        Request               $request,
        UserRepository        $userRepository,
        TokenStorageInterface $tokenStorage
    ): JsonResponse {

        // 1. Extraire le token du header Authorization
        $authHeader = $request->headers->get('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return $this->json(
                ['error' => 'Header Authorization manquant (attendu: Bearer <token>)'],
                Response::HTTP_UNAUTHORIZED
            );
        }
        $jwtString = substr($authHeader, 7);

        // 2. Décoder le payload (sans re-vérifier la signature)
        $parts = explode('.', $jwtString);
        if (count($parts) !== 3) {
            return $this->json(['error' => 'JWT malformé'], Response::HTTP_UNAUTHORIZED);
        }

        $b64     = strtr($parts[1], '-_', '+/');
        $b64     = str_pad($b64, strlen($b64) + (4 - strlen($b64) % 4) % 4, '=');
        $payload = json_decode(base64_decode($b64), true);

        if (!is_array($payload)) {
            return $this->json(['error' => 'Payload JWT illisible'], Response::HTTP_UNAUTHORIZED);
        }

        // 3. CORRECTION PRINCIPALE : extraire l'email
        //
        // LexikJWT génère le payload en appelant getUserIdentifier()
        // sur l'entité User. Comme getUserIdentifier() retourne l'email,
        // le payload contient : { "username": "email@example.com", ... }
        //
        // Selon la config user_identity_field, le champ peut varier.
        // On cherche dans tous les champs possibles :
        $email = null;

        // Cas 1 : champ "username" contient un email (config par défaut LexikJWT)
        if (!empty($payload['username']) && str_contains($payload['username'], '@')) {
            $email = $payload['username'];
        }
        // Cas 2 : champ "email" explicite (si user_identity_field: email)
        elseif (!empty($payload['email'])) {
            $email = $payload['email'];
        }
        // Cas 3 : champ "sub" (standard JWT)
        elseif (!empty($payload['sub']) && str_contains($payload['sub'], '@')) {
            $email = $payload['sub'];
        }
        // Cas 4 : "username" sans @ → c'est peut-être le username, pas l'email
        //         On ne peut pas trouver l'user → erreur explicite
        elseif (!empty($payload['username'])) {
            return $this->json([
                'error' => sprintf(
                    'Le JWT contient username="%s" qui n\'est pas un email. ' .
                    'Vérifiez que getUserIdentifier() retourne l\'email et non le username dans User.php, ' .
                    'et que user_identity_field: email est défini dans lexik_jwt_authentication.yaml.',
                    $payload['username']
                )
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (!$email) {
            return $this->json([
                'error' => 'Email introuvable dans le JWT. Clés disponibles : ' . implode(', ', array_keys($payload))
            ], Response::HTTP_UNAUTHORIZED);
        }

        // 4. Chercher l'utilisateur en base
        $user = $userRepository->findOneBy(['email' => $email]);
        if (!$user) {
            return $this->json(
                ['error' => "Utilisateur avec email '$email' introuvable en base."],
                Response::HTTP_NOT_FOUND
            );
        }

        // 5. Créer le token Symfony et sauvegarder en session
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage->setToken($token);

        $session = $request->getSession();
        $session->set('_security_main', serialize($token));
        $session->save();

        return $this->json([
            'success'  => true,
            'email'    => $user->getEmail(),
            'username' => $user->getUsername(),
            'roles'    => $user->getRoles(),
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // JWT-FOR-SESSION
    //
    // Génère un JWT pour un utilisateur déjà connecté par formulaire.
    // Permet à base.html.twig de synchroniser le JWT dans localStorage.
    // ─────────────────────────────────────────────────────────
    #[Route('/auth/jwt-for-session', name: 'auth_jwt_for_session', methods: ['POST'])]
    public function jwtForSession(JWTTokenManagerInterface $jwtManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non connecté'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'token'    => $jwtManager->create($user),
            'email'    => $user->getUserIdentifier(),
            'username' => method_exists($user, 'getUsername') ? $user->getUsername() : $user->getUserIdentifier(),
            'roles'    => $user->getRoles(),
        ]);
    }
}