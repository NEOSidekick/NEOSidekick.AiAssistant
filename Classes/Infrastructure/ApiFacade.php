<?php

namespace NEOSidekick\AiAssistant\Infrastructure;

use GuzzleHttp\Psr7\Uri;
use JsonException;
use Neos\Flow\Annotations as Flow;
use NEOSidekick\AiAssistant\Exception\GetMostRelevantInternalSeoLinksTimeoutException;
use Psr\Http\Client\ClientExceptionInterface;

class ApiFacade
{
    /**
     * @Flow\Inject
     * @var ApiClient
     */
    protected $apiClient;

    /**
     * @param string[] $hosts
     * @return Uri[]
     * @throws ClientExceptionInterface
     * @throws JsonException
     * @throws GetMostRelevantInternalSeoLinksTimeoutException
     */
    public function getMostRelevantInternalSeoUrisByHosts(array $hosts, string $interfaceLanguage): array
    {
        $apiResponse = $this->apiClient->getMostRelevantInternalSeoLinksByHosts($hosts, $interfaceLanguage);

        return self::deduplicateArrayOfUriStrings($apiResponse);
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
