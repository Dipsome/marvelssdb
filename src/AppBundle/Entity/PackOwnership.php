<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="AppBundle\Repository\PackOwnershipRepository")
 * @ORM\Table(name="pack_ownership")
 */
class PackOwnership
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\User")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=false)
     */
    private User $user;

    /**
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\Pack")
     * @ORM\JoinColumn(name="pack_id", referencedColumnName="id", nullable=false)
     */
    private Pack $pack;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getPack(): Pack
    {
        return $this->pack;
    }

    public function setPack(Pack $pack): self
    {
        $this->pack = $pack;
        return $this;
    }

    // Helper methods for compatibility with controller
    public function getUserId(): int
    {
        return $this->user->getId();
    }

    public function getPackId(): int
    {
        return $this->pack->getId();
    }
}