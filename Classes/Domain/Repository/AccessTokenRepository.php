<?php
namespace NEOSidekick\AiAssistant\Domain\Repository;

/*
 * This file is part of the NEOSidekick.AiAssistant package.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Repository;

/**
 * @Flow\Scope("singleton")
 */
class AccessTokenRepository extends Repository
{
    /**
     * @var array
     */
    protected $defaultOrderings = [
        'validUntil' => \Neos\Flow\Persistence\QueryInterface::ORDER_DESCENDING
    ];

    /**
     * Find a token by its identifier
     *
     * @param string $token
     * @return \NEOSidekick\AiAssistant\Domain\Model\AccessToken|null
     */
    public function findByToken(string $token): ?\NEOSidekick\AiAssistant\Domain\Model\AccessToken
    {
        return $this->findByIdentifier($token);
    }

    /**
     * Find all valid tokens
     *
     * @return \Neos\Flow\Persistence\QueryResultInterface
     */
    public function findValid()
    {
        $now = new \DateTimeImmutable();
        return $this->createQuery()
            ->matching($this->createQuery()->greaterThan('validUntil', $now))
            ->execute();
    }
}
