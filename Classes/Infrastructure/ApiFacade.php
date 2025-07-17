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
     * Send webhook requests to multiple endpoints
     *
     * @param string $eventName The name of the event
     * @param array $payload The payload to send
     * @param array $endpoints Array of endpoint URLs to send the webhook to
     * @return void
     */
    public function sendWebhookRequests(string $eventName, array $payload, array $endpoints): void
    {
        if (empty($endpoints[$eventName])) {
            return;
        }

        $endpointUrls = $endpoints[$eventName];
        foreach ($endpointUrls as $endpointUrl) {
            $this->sendWebhookRequest($endpointUrl, $payload);
        }
    }

    /**
     * Send a webhook request to a single endpoint
     *
     * @param string $url The URL to send the webhook to
     * @param array $payload The payload to send
     * @return void
     */
    public function sendWebhookRequest(string $url, array $payload): void
    {
        try {
            $client = new Client();
            $client->post($url, [
                'json' => $payload
            ]);
        } catch (\Exception $e) {
            $this->systemLogger->error('Webhook request failed: ' . $e->getMessage(), [
                'packageKey' => 'NEOSidekick.AiAssistant',
                'exception' => $e
            ]);
        }
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
