<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * UserPackOwnership
 * @ORM\Entity
 * @ORM\Table(name="user_pack_ownership")
 */
class UserPackOwnership
{
    /**
     * @var int
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @var User
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\User", inversedBy="packOwnerships")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=false)
     */
    private $user;

    /**
     * @var Pack
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\Pack")
     * @ORM\JoinColumn(name="pack_id", referencedColumnName="id", nullable=false)
     */
    private $pack;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getPack(): ?Pack
    {
        return $this->pack;
    }

    public function setPack(Pack $pack): self
    {
        $this->pack = $pack;
        return $this;
    }
}