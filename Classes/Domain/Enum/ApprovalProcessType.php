<?php
namespace NEOSidekick\AiAssistant\Domain\Enum;

/*
 * This file is part of the NEOSidekick.AiAssistant package.
 */

/**
 * Approval process type interface
 */
interface ApprovalProcessType
{
    /**
     * Approve automatically
     */
    public const APPROVE_AUTOMATICALLY = 'approve-automatically';

    /**
     * Request approval via email
     */
    public const REQUEST_VIA_EMAIL = 'request-via-email';

    /**
     * Request approval via Slack
     */
    public const REQUEST_VIA_SLACK = 'request-via-slack';
}
