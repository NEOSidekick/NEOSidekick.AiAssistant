<?php
namespace NEOSidekick\AiAssistant\Domain\Model;

/*
 * This file is part of the NEOSidekick.AiAssistant package.
 */

use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;
use Ramsey\Uuid\Uuid;

/**
 * @Flow\Entity
 */
class AccessToken
{
    /**
     * The unique token identifier
     *
     * @var string
     * @ORM\Id
     * @ORM\Column(length=40)
     */
    protected $token;

    /**
     * The date and time until which the token is valid
     *
     * @var \DateTimeImmutable
     */
    protected $validUntil;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->token = Uuid::uuid4()->toString();
        $this->validUntil = (new \DateTimeImmutable())->add(new \DateInterval('PT10M'));
    }

    /**
     * @return string
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * @return \DateTimeImmutable
     */
    public function getValidUntil(): \DateTimeImmutable
    {
        return $this->validUntil;
    }

    /**
     * Checks if the token is still valid
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return new \DateTimeImmutable() < $this->validUntil;
    }
}
