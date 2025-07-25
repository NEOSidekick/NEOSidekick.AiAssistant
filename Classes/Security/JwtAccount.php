<?php

namespace NEOSidekick\AiAssistant\Security;

use Neos\Flow\Security\Account;
use Neos\Party\Domain\Model\AbstractParty;
use stdClass;

class JwtAccount extends Account
{
    /**
     * @var stdClass
     */
    protected $claims;

    /**
     * @var AbstractParty
     */
    protected $party;

    /**
     * @param AbstractParty $party
     */
    public function setParty(AbstractParty $party)
    {
        $this->party = $party;
    }

    /**
     * @return AbstractParty
     */
    public function getParty(): AbstractParty
    {
        return $this->party;
    }

    public function setClaims(stdClass $claims): void
    {
        $this->claims = $claims;
    }

    /**
     * @param $name
     * @param $args
     * @return mixed
     */
    public function __call($name, $args)
    {
        if (str_starts_with($name, 'get')) {
            $name = \lcfirst(\substr($name, 3));
            return $this->claims->{$name};
        }
        throw new \BadMethodCallException($name . ' is not callable on this object');
    }

    public function __toString(): string
    {
        return $this->accountIdentifier;
    }
}
