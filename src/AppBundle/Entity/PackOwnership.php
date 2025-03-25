<?php

namespace AppBundle\Entity;

class PackOwnership
{
    private $id;
    private $userId;
    private $packId;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function getPackId(): int
    {
        return $this->packId;
    }

    public function setPackId(int $packId): self
    {
        $this->packId = $packId;
        return $this;
    }
}