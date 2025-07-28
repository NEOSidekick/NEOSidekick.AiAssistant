<?php

namespace NEOSidekick\AiAssistant\Infrastructure;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use JsonException;
use Neos\Flow\Annotations as Flow;
use NEOSidekick\AiAssistant\Exception\GetMostRelevantInternalSeoLinksApiException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;

class ApiFacade
{
    /**
     * @Flow\Inject
     * @var ApiClient
     */
    protected $apiClient;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $systemLogger;

    /**
     * @param string[] $hosts
     * @return Uri[]
     * @throws ClientExceptionInterface
     * @throws JsonException
     * @throws GetMostRelevantInternalSeoLinksApiException
     */
    public function getMostRelevantInternalSeoUrisByHosts(array $hosts, string $interfaceLanguage): array
    {
        $apiResponse = $this->apiClient->getMostRelevantInternalSeoLinksByHosts($hosts, $interfaceLanguage);

        return self::deduplicateArrayOfUriStrings($apiResponse);
    }

    /**
     * Send a batch request to the modules batch API endpoint
     *
     * @param array $requests The requests to send to the batch API
     * @param string|null $webhookUrl Optional webhook URL for asynchronous responses
     * @param string|null $webhookAuthorizationHeader Optional authorization header for the webhook
     * @return void
     */
    public function sendBatchModuleRequest(array $requests, ?string $webhookUrl = null, ?string $webhookAuthorizationHeader = null): void
    {
        $this->apiClient->sendBatchModuleRequest($requests, $webhookUrl, $webhookAuthorizationHeader);
    }

    /**
     * @param array $apiResponse
     *
     * @return array
     */
    private static function deduplicateArrayOfUriStrings(array $apiResponse): array
    {
        return array_values(array_reduce($apiResponse, static function (array $carry, string $uriString) {
            $uri = new Uri($uriString);
            $carry[$uri->getPath() ?: '/'] = $uri;
            return $carry;
        }, []));
    }
}
