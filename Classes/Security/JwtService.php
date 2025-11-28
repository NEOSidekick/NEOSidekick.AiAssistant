<?php

namespace NEOSidekick\AiAssistant\Security;

use Neos\Flow\Annotations as Flow;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Utility\Algorithms;

class JwtService
{
    /**
     * @var StringFrontend
     * @Flow\Inject
     */
    protected $cache;

    /**
     * @var string|null
     * The encryption key used for HS256 algorithm (HMAC with SHA-256)
     * This is a symmetric key suitable for HMAC-based algorithms
     */
    protected $encryptionKey;

    /**
     * Creates a JWT token using HS256 algorithm (HMAC with SHA-256)
     * HS256 uses a symmetric key for both signing and verification
     *
     * @param array $payload The data to be encoded in the JWT
     * @return string The encoded JWT
     */
    public function createJsonWebToken(array $payload): string
    {
        return JWT::encode($payload, $this->getEncryptionKey(), 'HS256');
    }

    /**
     * Decodes a JWT token using HS256 algorithm (HMAC with SHA-256)
     * The same symmetric key used for signing is used for verification
     *
     * @param string $encodedJWT The JWT to decode
     * @return object The JWT payload as an object
     */
    public function decodeJsonWebToken(string $encodedJWT): object
    {
        $key = new Key($this->getEncryptionKey(), 'HS256');
        return JWT::decode($encodedJWT, $key);
    }

    public function getEncryptionKey(): ?string
    {
        if ($this->encryptionKey === null) {
            $this->encryptionKey = $this->cache->get('encryptionKey');
        }
        if ($this->encryptionKey === false && file_exists(FLOW_PATH_DATA . 'Persistent/EncryptionKey')) {
            $this->encryptionKey = file_get_contents(FLOW_PATH_DATA . 'Persistent/EncryptionKey');
        }
        if ($this->encryptionKey === false) {
            $this->encryptionKey = bin2hex(Algorithms::generateRandomBytes(48));
            $this->cache->set('encryptionKey', $this->encryptionKey);
        }
        return $this->encryptionKey;
    }
}
