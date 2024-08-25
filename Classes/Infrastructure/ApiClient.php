<?php

namespace NEOSidekick\AiAssistant\Infrastructure;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Stream;
use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Client\Browser;
use Neos\Flow\Http\Client\CurlEngine;
use Neos\Flow\Http\Client\CurlEngineException;
use NEOSidekick\AiAssistant\Exception\GetMostRelevantInternalSeoLinksTimeoutException;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * @Flow\Scope("singleton")
 */
class ApiClient
{

    /**
     * @Flow\InjectConfiguration(path="apikey")
     * @var string
     */
    protected string $apiKey;

    /**
     * @Flow\InjectConfiguration(path="developmentBuild")
     * @var bool
     */
    protected bool $isDevelopmentBuild;

    /**
     * @var string
     */
    protected string $apiDomain;

    /**
     * @var Browser
     */
    protected $browser;

    public function initializeObject(): void
    {
        if (isset($this->isDevelopmentBuild) && $this->isDevelopmentBuild === true) {
            $this->apiDomain = 'https://api-staging.neosidekick.com';
        } else {
            $this->apiDomain = 'https://api.neosidekick.com';
        }
        $browserRequestEngine = new CurlEngine();
        $this->browser = new Browser();
        $this->browser->setRequestEngine($browserRequestEngine);
    }

    /**
     * @param array $hosts
     * @param string $interfaceLanguage
     *
     * @return array
     * @throws ClientExceptionInterface
     * @throws CurlEngineException
     * @throws JsonException
     * @throws GetMostRelevantInternalSeoLinksTimeoutException
     */
    public function getMostRelevantInternalSeoLinksByHosts(array $hosts, string $interfaceLanguage): array
    {
        $request = new ServerRequest('POST', $this->apiDomain . '/api/v1/find-most-relevant-internal-seo-links');
        $request = $request->withAddedHeader('Accept', 'application/json');
        $request = $request->withAddedHeader('Authorization', 'Bearer ' . $this->apiKey);
        $request = $request->withAddedHeader('Content-Type', 'application/json');
        /** @var Request $request */
        $request = $request->withBody(self::streamFor(json_encode(['language' => $interfaceLanguage, 'uris' => $hosts], JSON_THROW_ON_ERROR)));
        try {
            $response = $this->browser->sendRequest($request);
        } catch (CurlEngineException $e) {
            // cURL error 28 stands for operation timed out aka the API did not answer within the given time
            if (str_starts_with($e->getMessage(), 'cURL reported error code 28')) {
                throw new GetMostRelevantInternalSeoLinksTimeoutException();
            }

            throw $e;
        }

        $responseBodyContents = $response->getBody()->getContents();
        $responseBodyContentsFromJson = json_decode($responseBodyContents, true);
        if ($responseBodyContentsFromJson === null) {
            throw new RuntimeException(sprintf('Invalid JSON response from NEOSidekick API: %s', $responseBodyContents));
        }
        if ($response->getStatusCode() !== 200) {
            if (isset($responseBodyContentsFromJson['message'])) {
                throw new RuntimeException($responseBodyContentsFromJson['message']);
            }
            throw new RuntimeException(sprintf('Invalid status code from NEOSidekick API %s and message: %s', $response->getStatusCode(), $responseBodyContents));
        }
        if (!isset($responseBodyContentsFromJson['data']['uris'])) {
            throw new RuntimeException(sprintf('Invalid response from NEOSidekick API, "data.uris" is missing: %s', $responseBodyContents));
        }
        return $responseBodyContentsFromJson['data']['uris'];
    }

    private static function streamFor(string $jsonData): Stream
    {
        // Open a temporary memory stream and write the JSON data to it
        $resource = fopen('php://temp', 'rb+');
        fwrite($resource, $jsonData);
        rewind($resource);

        // Create a StreamInterface from the resource
        return new Stream($resource);
    }
}
