Projet académique — ING-A2-GL | ISSAT Sousse | Année universitaire 2025-2026

 **Description
Application web complète de gestion de réservations d'événements développée avec Symfony 7, intégrant une authentification moderne combinant JWT et Passkeys (WebAuthn/FIDO2).

 **Fonctionnalités

Côté utilisateur

🔐 Connexion classique (email + mot de passe) sécurisée avec JWT
🔑 Inscription et connexion avec clé d'accès Passkey (WebAuthn)
📅 Consultation de la liste des événements (sans connexion requise)
🎫 Réservation d'une place (connexion obligatoire)
✉️ Email de confirmation automatique après réservation
📱 Interface responsive

Côté administrateur

🛡️ Dashboard sécurisé (ROLE_ADMIN uniquement)
➕ Création d'événements
✏️ Modification d'événements
🗑️ Suppression d'événements
👥 Consultation des réservations par événement


 ** Technologies
TechnologieRôlePHP 8.2 + Symfony 7Framework backendPostgreSQL 16Base de donnéesDocker ComposeConteneurisationLexikJWT BundleTokens JWTGesdinet Refresh TokenRefresh tokenswebauthn-lib ^4.9Passkeys / WebAuthnTwig + Bootstrap 5.3 Frontend smtp+Gmail emails,  (développement)NginxServeur web

 ** Structure du projet
MiniProjet2A-EventReservation/
├── config/
│   ├── jwt/                              # Clés RSA (non versionnées)
│   └── packages/
│       ├── security.yaml
│       ├── lexik_jwt_authentication.yaml
│       └── gesdinet_jwt_refresh_token.yaml
├── docker/nginx/default.conf
├── public/js/auth.js                     # Client WebAuthn + JWT
├── src/
│   ├── Controller/
│   │   ├── Admin/AdminEventController.php
│   │   ├── AuthApiController.php         # API Passkeys
│   │   ├── EventController.php
│   │   ├── HomeController.php
│   │   └── SecurityController.php
│   ├── Entity/
│   │   ├── Event.php
│   │   ├── Reservation.php
│   │   ├── RefreshToken.php
│   │   ├── User.php
│   │   └── WebauthnCredential.php
│   ├── Form/
│   ├── Repository/
│   └── Service/PasskeyAuthService.php
├── templates/
│   ├── admin/
│   ├── emails/
│   ├── event/
│   ├── home/
│   └── security/
├── compose.yaml
├── Dockerfile
└── .env

** Installation
Prérequis

Docker Desktop installé et démarré
Git installé
Navigateur supportant WebAuthn : Chrome 108+, Firefox 113+, Safari 16+

1. Cloner le projet
bashgit clone https://github.com/rahma-918/MiniProjet2A-EventReservation.git
cd MiniProjet2A-EventReservation
2. Créer le fichier .env.local

envAPP_ENV=dev
APP_SECRET=changez_ce_secret_par_32_caracteres

DATABASE_URL="postgresql://user:password@db:5432/event_db?serverVersion=16&charset=utf8"

JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=votre_passphrase_ici

MAILER_DSN=smtp://votre_adresse_email:votre_mot_de_passe@smtp.gmail.com:587?encryption=tls&auth_mode=login

APP_DOMAIN=localhost
WEBAUTHN_RP_NAME="EventReservation"
APP_ORIGIN=http://localhost:8080


3. Démarrer Docker
bashdocker compose up -d
docker compose ps
4. Générer les clés JWT
bashdocker compose exec php mkdir -p config/jwt

docker compose exec php sh -c "openssl genpkey -out config/jwt/private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096 -pass pass:VOTRE_PASSPHRASE"

docker compose exec php sh -c "openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:VOTRE_PASSPHRASE"

docker compose exec php chmod 600 config/jwt/private.pem

Remplacez VOTRE_PASSPHRASE par la valeur de JWT_PASSPHRASE de votre .env.local

5. Migrations et données initiales
bashdocker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console app:create-admin
docker compose exec php php bin/console cache:clear
6. Accéder à l'application
Application http://localhost:8080
admin http://localhost:8080/adminDashboard 
Compte admin par défaut : admin@eventix.com / admin123

🔐 Architecture de sécurité
Flux JWT + Passkeys
Utilisateur
    │
    ├─── Formulaire login ──► Symfony form_login ──► Session + JWT généré
    │
    └─── Passkey (clé) ──► API WebAuthn ──► JWT + Session Symfony créée
                                 │
                          /api/auth/login/verify
                          /auth/session-from-jwt

** Rôles
Public: Accueil, liste événements, login, register 
ROLE_USER:  +Réservation d'événements
ROLE_ADMIN: + CRUD événements, dashboard, réservations

🌿 Branches GitHub
main          ← Code stable
dev           ← Intégration
├── feature/entities
├── feature/auth-jwt-passkeys
├── feature/admin-dashboard
└── feature/frontend


** Production (Gmail)

Activez la validation en 2 étapes sur Google
Créez un mot de passe d'application
Configurez :

envMAILER_DSN=smtp://votre.email@gmail.com:MOT_DE_PASSE_APP@smtp.gmail.com:587?encryption=tls&auth_mode=login

🐳 Services Docker
app_db    5432  PostgreSQL 16
app_php   -     PHP 8.2-fpm
app_nginx 8080  Nginx
mailer    58286 smtp+Gmail

🔧 Commandes utiles
bash# Démarrer / arrêter
docker compose up -d
docker compose down

# Cache
docker compose exec php php bin/console cache:clear

# Migrations
docker compose exec php php bin/console doctrine:migrations:diff
docker compose exec php php bin/console doctrine:migrations:migrate

# Logs
docker compose logs -f php

# Terminal PHP
docker compose exec php bash

** Auteur
RAHMA CHKEL ING-A2-GL

** Références

Symfony 7 Documentation
LexikJWT Bundle
WebAuthn Level 2 — W3C
FIDO Alliance — Passkeys
JWT RFC 7519