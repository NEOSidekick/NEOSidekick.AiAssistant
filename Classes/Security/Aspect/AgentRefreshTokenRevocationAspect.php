<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Security\Aspect;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Context;
use Neos\Neos\Domain\Model\User;
use NEOSidekick\AiAssistant\Service\AgentRefreshTokenService;
use Psr\Log\LoggerInterface;

/**
 * Revokes the plugin's refresh credentials on the two events that must kill an editor's
 * agent tokens plugin-side (authoritative at the token record, no network dependency):
 * an explicit LOGOUT and a PASSWORD CHANGE. A merely expired session leaves them
 * untouched. Both advices are around advices for ordering reasons spelled out below, and
 * both stay trivial - they collect account UUIDs and hand them to
 * AgentRefreshTokenService, whose never-throws discipline keeps a broken revocation from
 * breaking the advised operation.
 *
 * LOGOUT - an around advice on AuthenticationProviderManager::logout() instead of a slot
 * on its `loggedOut` signal for a load-bearing ordering reason: logout() sets every token
 * to NO_CREDENTIALS_GIVEN BEFORE it emits the signal, and an unauthenticated token's
 * getAccount() returns null - a signal slot therefore never sees the accounts being
 * logged out. The advice captures the authenticated accounts BEFORE proceeding into the
 * real logout and revokes their records afterwards. logout() is only ever called on
 * deliberate logout (never on session expiry/GC), so the aspect fires on exactly the same
 * occasions the signal would have. Scope: CHAT-marked families only - the marker is
 * pinned in AgentRefreshTokenService::revokeRefreshTokensOfAccounts(), not here. A Neos
 * logout ends the Neos chat session; an external consumer's credential chain is not bound
 * to the editor's backend session and must survive it.
 *
 * PASSWORD CHANGE - an around advice on Neos\Neos\Domain\Service\UserService::
 * setUserPassword(), the single choke point every path goes through (self-service
 * settings, admin reset, CLI) and the only place an existing account gets a new
 * credentialsSource. The accounts come from the join point's `user` argument, never from
 * the security context: an admin reset and the CLI change SOMEONE ELSE's password, and
 * the CLI has no authenticated account at all. Revocation runs BEFORE proceed() because
 * revoke-first is the fail-closed ordering: setUserPassword() destroys the user's OTHER
 * sessions before re-hashing and deliberately KEEPS the changing session alive, so a
 * proceed-first advice that threw mid-change would leave agent credentials minted under
 * the old password fully live. Over-revoking costs one silent re-consent; under-revoking
 * costs the whole window. Third-party user management that writes `credentialsSource`
 * without going through setUserPassword() bypasses this advice entirely. Scope: ALL
 * consumer markers - a password change means the credentials may have leaked, so
 * everything minted under the old trust dies, external connections included.
 *
 * Nothing here may break the advised operation: the captures are swallow-and-log and the
 * revocations run through AgentRefreshTokenService's own never-throws discipline.
 *
 * @Flow\Aspect
 * @Flow\Scope("singleton")
 */
class AgentRefreshTokenRevocationAspect
{
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
     * @var AgentRefreshTokenService
     */
    protected AgentRefreshTokenService $agentRefreshTokenService;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Around("method(Neos\Flow\Security\Authentication\AuthenticationProviderManager->logout())")
     * @return mixed
     */
    public function revokeRefreshTokensOnLogout(JoinPointInterface $joinPoint)
    {
        $accountUuids = $this->collectAuthenticatedAccountUuids();

        $result = $joinPoint->getAdviceChain()->proceed($joinPoint);

        $this->agentRefreshTokenService->revokeRefreshTokensOfAccounts($accountUuids);

        return $result;
    }

    /**
     * @Flow\Around("method(Neos\Neos\Domain\Service\UserService->setUserPassword())")
     * @return mixed
     */
    public function revokeRefreshTokensOnPasswordChange(JoinPointInterface $joinPoint)
    {
        $this->agentRefreshTokenService->revokeAllRefreshTokensOfAccounts(
            $this->collectAccountUuidsOfUserArgument($joinPoint)
        );

        return $joinPoint->getAdviceChain()->proceed($joinPoint);
    }

    /**
     * The account UUIDs of the user whose password is being changed, read from the join
     * point's `user` argument - never from the security context, which holds the ADMIN in
     * an admin reset and nobody at all on the CLI. Never throws.
     *
     * @return array<string>
     */
    protected function collectAccountUuidsOfUserArgument(JoinPointInterface $joinPoint): array
    {
        return $this->accountUuidsOf(
            static function () use ($joinPoint): iterable {
                $user = $joinPoint->getMethodArgument('user');

                return $user instanceof User ? $user->getAccounts() : [];
            },
            'password change'
        );
    }

    /**
     * The account UUIDs of every currently AUTHENTICATED token - captured before
     * logout() de-authenticates them. Never throws.
     *
     * @return array<string>
     */
    protected function collectAuthenticatedAccountUuids(): array
    {
        return $this->accountUuidsOf(
            function (): iterable {
                if (!$this->securityContext->isInitialized()) {
                    return [];
                }

                $accounts = [];
                foreach ($this->securityContext->getAuthenticationTokens() as $token) {
                    $account = $token->getAccount();
                    if ($account !== null) {
                        $accounts[] = $account;
                    }
                }

                return $accounts;
            },
            'logout'
        );
    }

    /**
     * Turns a collector's resolved accounts into their deduplicated persistence UUIDs.
     *
     * Holds the never-throws discipline for BOTH revocation paths: the source resolution
     * itself runs inside the try, so a broken security context, a detached account or a
     * missing join point argument degrades to "revoke nothing" plus one error log line -
     * never into a failed logout or a failed password change.
     *
     * @param callable(): iterable<Account> $resolveAccounts Source resolution of the calling collector
     * @param string $eventDescription Names the revocation event in the failure log line
     * @return array<string>
     */
    private function accountUuidsOf(callable $resolveAccounts, string $eventDescription): array
    {
        try {
            $accountUuids = [];
            foreach ($resolveAccounts() as $account) {
                /** @var Account $account */
                $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
                if (is_string($accountUuid) && $accountUuid !== '') {
                    $accountUuids[$accountUuid] = true;
                }
            }

            return array_keys($accountUuids);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf(
                    'NEOSidekick agent refresh: collecting accounts for %s revocation failed: %s',
                    $eventDescription,
                    $e->getMessage()
                ),
                LogEnvironment::fromMethodName(__METHOD__)
            );

            return [];
        }
    }
}
