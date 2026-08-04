<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Domain\Service\WorkspaceService;

/**
 * Neos 9 replacement for the removed `UserService::getPersonalWorkspaceName()` (the method
 * still exists in Neos 9 but throws, see neos/neos-development-collection#5418): personal
 * workspaces are resolved through the WorkspaceService metadata now.
 *
 * @Flow\Scope("singleton")
 */
class PersonalWorkspaceService
{
    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected WorkspaceService $workspaceService;

    #[Flow\Inject]
    protected ContentRepositoryProvider $contentRepositoryProvider;

    /**
     * The personal workspace name of the currently authenticated backend user, or null
     * when there is no authenticated user or no personal workspace is assigned yet
     * (mirrors the old method's null contract).
     */
    public function getPersonalWorkspaceName(): ?string
    {
        $currentUser = $this->userService->getCurrentUser();
        if ($currentUser === null) {
            return null;
        }
        try {
            return $this->workspaceService->getPersonalWorkspaceForUser(
                $this->contentRepositoryProvider->getContentRepositoryId(),
                $currentUser->getId()
            )->workspaceName->value;
        } catch (\RuntimeException) {
            // no personal workspace assigned yet (user never opened the backend UI)
            return null;
        }
    }
}
