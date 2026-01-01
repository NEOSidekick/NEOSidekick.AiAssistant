<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller\Trait;

use JsonException;

/**
 * Trait providing Bearer token authentication for API controllers.
 *
 * This trait centralizes the authentication logic used across multiple
 * API controllers to ensure consistent security handling.
 */
trait ApiAuthenticationTrait
{
    /**
     * Validate the incoming request's Bearer token against the configured API key.
     *
     * Sets an appropriate HTTP status code and returns a JSON-encoded error payload when validation fails.
     *
     * @return string|null JSON-encoded error payload on failure; `null` when authentication succeeds.
     * @throws JsonException If encoding the error payload fails.
     */
    protected function validateAuthentication(): ?string
    {
        // Reject all requests when API key is not configured to prevent empty-token bypass
        if (empty($this->apiKey)) {
            $this->response->setStatusCode(503);
            return json_encode([
                'error' => 'Service Unavailable',
                'message' => 'API key is not configured'
            ], JSON_THROW_ON_ERROR);
        }

        $authHeader = $this->request->getHttpRequest()->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            $this->response->setStatusCode(401);
            return json_encode([
                'error' => 'Unauthorized',
                'message' => 'Missing Authorization header'
            ], JSON_THROW_ON_ERROR);
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            $this->response->setStatusCode(401);
            return json_encode([
                'error' => 'Unauthorized',
                'message' => 'Invalid Authorization header format, expected Bearer token'
            ], JSON_THROW_ON_ERROR);
        }

        $token = substr($authHeader, 7);

        // Also reject empty tokens to prevent bypass with "Authorization: Bearer "
        if (empty($token) || !hash_equals($this->apiKey, $token)) {
            $this->response->setStatusCode(401);
            return json_encode([
                'error' => 'Unauthorized',
                'message' => 'Invalid API key'
            ], JSON_THROW_ON_ERROR);
        }

        return null;
    }
}
