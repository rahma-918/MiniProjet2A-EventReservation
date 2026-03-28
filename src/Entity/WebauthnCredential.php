<?php
// =============================================
// src/Entity/WebauthnCredential.php — VERSION CORRIGÉE
// =============================================
namespace App\Entity;

use App\Repository\WebauthnCredentialRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebauthnCredentialRepository::class)]
class WebauthnCredential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 500)]  // CORRECTION : 255 peut être trop court pour un credentialId base64
    private ?string $credentialId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $credentialSource = null;   // JSON sérialisé de PublicKeyCredentialSource

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $name = null;               // ex: "Mon iPhone", "MacBook"

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $lastUsedAt = null;

    // CORRECTION : renommé de 'userProp' en 'user' (plus clair)
    // et mappedBy mis à jour dans User.php en conséquence
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'webauthnCredentials')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    // SUPPRIMÉ : setId(Uuid) — incohérent car l'id est un int auto-généré

    public function getCredentialId(): ?string
    {
        return $this->credentialId;
    }

    public function setCredentialId(string $credentialId): static
    {
        $this->credentialId = $credentialId;
        return $this;
    }

    public function getCredentialSource(): ?string
    {
        return $this->credentialSource;
    }

    public function setCredentialSource(?string $credentialSource): static
    {
        $this->credentialSource = $credentialSource;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    // Met à jour la dernière utilisation (appelé après une auth réussie)
    public function touch(): static
    {
        $this->lastUsedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }
}