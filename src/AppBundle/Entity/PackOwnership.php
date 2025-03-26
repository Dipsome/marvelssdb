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
     * @var integer
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\User")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=false)
     * @var \AppBundle\Entity\User
     */
    private $user;

    /**
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\Pack")
     * @ORM\JoinColumn(name="pack_id", referencedColumnName="id", nullable=false)
     * @var \AppBundle\Entity\Pack
     */
    private $pack;

    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get user
     *
     * @return \AppBundle\Entity\User
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set user
     *
     * @param \AppBundle\Entity\User $user
     * @return PackOwnership
     */
    public function setUser(\AppBundle\Entity\User $user)
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Get pack
     *
     * @return \AppBundle\Entity\Pack
     */
    public function getPack()
    {
        return $this->pack;
    }

    /**
     * Set pack
     *
     * @param \AppBundle\Entity\Pack $pack
     * @return PackOwnership
     */
    public function setPack(\AppBundle\Entity\Pack $pack)
    {
        $this->pack = $pack;
        return $this;
    }

    /**
     * Get user id
     *
     * @return integer
     */
    public function getUserId()
    {
        return $this->user->getId();
    }

    /**
     * Get pack id
     *
     * @return integer
     */
    public function getPackId()
    {
        return $this->pack->getId();
    }
}