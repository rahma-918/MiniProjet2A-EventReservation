<?php
// =============================================
// src/Entity/User.php — VERSION CORRIGÉE
// =============================================
namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    // AJOUT : username pour l'affichage
    #[ORM\Column(length: 100)]
    private ?string $username = null;

    #[ORM\Column]
    private array $roles = [];

    // AJOUT : password hashé (peut être null si on utilise seulement Passkeys)
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    /**
     * @var Collection<int, WebauthnCredential>
     */
    #[ORM\OneToMany(targetEntity: WebauthnCredential::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $webauthnCredentials;

    /**
     * @var Collection<int, Reservation>
     */
    #[ORM\OneToMany(targetEntity: Reservation::class, mappedBy: 'user')]
    private Collection $reservations;

    public function __construct()
    {
        $this->webauthnCredentials = new ArrayCollection();
        $this->reservations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;
        return $this;
    }

    // REQUIS par UserInterface — identifiant unique de l'utilisateur
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER'; // chaque user a au moins ROLE_USER
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    // REQUIS par PasswordAuthenticatedUserInterface
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;
        return $this;
    }

    // REQUIS par UserInterface — efface les données sensibles temporaires
    public function eraseCredentials(): void
    {
        // Si vous stockez un mot de passe en clair temporairement, effacez-le ici
    }

    /**
     * @return Collection<int, WebauthnCredential>
     */
    public function getWebauthnCredentials(): Collection
    {
        return $this->webauthnCredentials;
    }

    public function addWebauthnCredential(WebauthnCredential $webauthnCredential): static
    {
        if (!$this->webauthnCredentials->contains($webauthnCredential)) {
            $this->webauthnCredentials->add($webauthnCredential);
            $webauthnCredential->setUser($this);
        }
        return $this;
    }

    public function removeWebauthnCredential(WebauthnCredential $webauthnCredential): static
    {
        if ($this->webauthnCredentials->removeElement($webauthnCredential)) {
            if ($webauthnCredential->getUser() === $this) {
                $webauthnCredential->setUser(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Reservation>
     */
    public function getReservations(): Collection
    {
        return $this->reservations;
    }
}