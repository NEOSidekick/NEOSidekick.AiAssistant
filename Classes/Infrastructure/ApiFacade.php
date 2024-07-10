<?php

namespace NEOSidekick\AiAssistant\Infrastructure;

use GuzzleHttp\Psr7\Uri;
use JsonException;
use Neos\Flow\Annotations as Flow;
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
     */
    public function getMostRelevantInternalSeoUrisByHosts(array $hosts): array
    {
        $apiResponse = $this->apiClient->getMostRelevantInternalSeoLinksByHosts($hosts);

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
