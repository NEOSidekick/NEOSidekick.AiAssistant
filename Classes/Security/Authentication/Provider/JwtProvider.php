<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Security\Authentication\Provider;

use Firebase\JWT\ExpiredException;
use NEOSidekick\AiAssistant\Domain\Repository\AgentRefreshTokenRecordRepository;
use NEOSidekick\AiAssistant\Security\Authentication\Token\JwtToken;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Authentication\Provider\AbstractProvider;
use Neos\Flow\Security\Authentication\TokenInterface;
use Neos\Flow\Security\Context;
use Neos\Flow\Security\Exception\UnsupportedAuthenticationTokenException;
use Neos\Flow\Session\SessionManagerInterface;

/**
 * Authentication provider that validates JWT Bearer tokens.
 *
 * Verifies the JWT (kid-discriminated, see AgentTokenService::verifyToken()), resolves
 * the Neos backend account, and populates the security context for API tool-call
 * endpoints. The two token generations differ in their liveness anchor:
 *
 *  - Legacy tokens (no `kid`): the embedded `session_id` must still exist in the live
 *    session store - validity dies with the Neos session.
 *  - New-generation tokens (our `kid`): signature + `exp` govern validity, plus a
 *    liveness read against the refresh token records - the token's `jti` must resolve to
 *    a record whose FAMILY still holds at least one unrevoked row. That is what makes
 *    logout, password change, re-consent supersession and the replay response kill
 *    unexpired access tokens instantly, without a session anchor. Family liveness rather
 *    than the record's own flag: rotation CAS-revokes the predecessor while the successor
 *    stays unrevoked, so a superseded token keeps working for its remaining `exp` and
 *    in-flight tool calls never 401 - every actual revocation primitive here is
 *    family-wide or broader.
 *
 * The per-request active-account check below covers both generations, so a deactivated or
 * deleted editor fails immediately regardless of `exp`.
 *
 * New-generation tokens additionally have their `account_id` claim pinned to the
 * persistence UUID of the account resolved via `sub`, so a token can never be replayed
 * against a different account that happens to reuse the same username.
 */
class JwtProvider extends AbstractProvider
{
    /**
     * @Flow\Inject
     * @var AgentTokenService
     */
    protected AgentTokenService $agentTokenService;

    /**
     * @Flow\Inject
     * @var SessionManagerInterface
     */
    protected SessionManagerInterface $sessionManager;

    /**
     * @Flow\Inject
     * @var AccountRepository
     */
    protected AccountRepository $accountRepository;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected Context $securityContext;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected PersistenceManagerInterface $persistenceManager;

    /**
     * @Flow\Inject
     * @var AgentRefreshTokenRecordRepository
     */
    protected AgentRefreshTokenRecordRepository $agentRefreshTokenRecordRepository;

    public function getTokenClassNames(): array
    {
        return [JwtToken::class];
    }

    /**
     * @param TokenInterface $authenticationToken
     * @throws UnsupportedAuthenticationTokenException
     */
    public function authenticate(TokenInterface $authenticationToken): void
    {
        if (!$authenticationToken instanceof JwtToken) {
            throw new UnsupportedAuthenticationTokenException(
                sprintf(
                    'This provider cannot authenticate the given token. The token must implement %s',
                    JwtToken::class
                ),
                1217339840
            );
        }

        $bearer = $authenticationToken->getBearer();
        if ($bearer === '') {
            $authenticationToken->setAuthenticationStatus(TokenInterface::NO_CREDENTIALS_GIVEN);
            return;
        }

        /*
         * Loaded outside the catch-all below, which turns everything into WRONG_CREDENTIALS and
         * thus into the authoritative rejection marker: a signing-key row that cannot be READ
         * must propagate marker-less instead, so the backend treats it as inconclusive. A
         * genuinely missing key does not throw here and is rejected inside the try.
         */
        $this->agentTokenService->preloadSigningKey($bearer);

        try {
            $payload = $this->agentTokenService->verifyToken($bearer);
        } catch (ExpiredException $e) {
            $authenticationToken->setRejectionReason(JwtToken::REJECTION_REASON_EXPIRED);
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
            return;
        } catch (\Throwable $e) {
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
            return;
        }

        if (!$this->agentTokenService->isNewGenerationToken($bearer)) {
            $sessionId = $payload['session_id'] ?? null;
            if ($sessionId === null) {
                $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
                return;
            }

            $session = $this->sessionManager->getSession($sessionId);
            if ($session === null) {
                $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
                return;
            }
        }

        $sub = $payload['sub'] ?? null;
        if ($sub === null || $sub === '') {
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
            return;
        }

        $account = null;
        $providerName = $this->options['lookupProviderName'] ?? 'Neos.Neos:Backend';
        $this->securityContext->withoutAuthorizationChecks(function () use ($sub, $providerName, &$account) {
            $account = $this->accountRepository->findActiveByAccountIdentifierAndAuthenticationProviderName(
                $sub,
                $providerName
            );
        });

        if ($account === null) {
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
            return;
        }

        if ($this->agentTokenService->isNewGenerationToken($bearer)) {
            $accountIdClaim = $payload['account_id'] ?? null;
            $resolvedAccountId = $this->persistenceManager->getIdentifierByObject($account);
            if (!is_string($accountIdClaim) || !is_string($resolvedAccountId) || !hash_equals($resolvedAccountId, $accountIdClaim)) {
                $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
                return;
            }

            /*
             * The liveness read is deliberately NOT wrapped in a catch: isStorageReady()
             * gates minting, so a new-generation token implies the table exists, and a
             * repository failure must surface as a non-401 the backend treats as
             * inconclusive - never as a silent authentication success.
             */
            $jti = $payload['jti'] ?? null;
            if (!is_string($jti) || $jti === '') {
                $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
                return;
            }

            /*
             * Read without authorization checks, exactly like the account lookup above:
             * the refresh records are authentication infrastructure, and a site
             * EntityPrivilege of the not-yet-authenticated subject must never constrain
             * (and thereby silently falsify) the liveness answer.
             */
            $refreshTokenRecord = null;
            $familyHasUnrevokedRecord = false;
            $this->securityContext->withoutAuthorizationChecks(function () use ($jti, &$refreshTokenRecord, &$familyHasUnrevokedRecord) {
                $refreshTokenRecord = $this->agentRefreshTokenRecordRepository->findOneByJti($jti);
                if ($refreshTokenRecord !== null) {
                    $familyHasUnrevokedRecord = $this->agentRefreshTokenRecordRepository->familyHasUnrevokedRecord(
                        $refreshTokenRecord->getFamilyId()
                    );
                }
            });

            if ($refreshTokenRecord === null || !$familyHasUnrevokedRecord) {
                $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
                return;
            }
        }

        $account->authenticationAttempted(TokenInterface::AUTHENTICATION_SUCCESSFUL);
        $authenticationToken->setAccount($account);
        $authenticationToken->setAuthenticationStatus(TokenInterface::AUTHENTICATION_SUCCESSFUL);
    }
}
