<?php

namespace NEOSidekick\AiAssistant\Infrastructure;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Stream;
use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Client\Browser;
use Neos\Flow\Http\Client\CurlEngine;
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
        $browserRequestEngine->setOption(CURLOPT_TIMEOUT, 10);
        $this->browser = new Browser();
        $this->browser->setRequestEngine($browserRequestEngine);
    }

    /**
     * @throws JsonException|ClientExceptionInterface
     */
    public function getMostRelevantInternalSeoLinksByHosts(array $hosts): array
    {
        $request = new ServerRequest('POST', $this->apiDomain . '/api/v1/find-most-relevant-internal-seo-links');
        $request = $request->withAddedHeader('Accept', 'application/json');
        $request = $request->withAddedHeader('Authorization', 'Bearer ' . $this->apiKey);
        $request = $request->withAddedHeader('Content-Type', 'application/json');
        /** @var Request $request */
        $request = $request->withBody(self::streamFor(json_encode(['uris' => $hosts], JSON_THROW_ON_ERROR)));
        $response = $this->browser->sendRequest($request);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(sprintf('Invalid status code from NEOSidekick API: %s', $response->getStatusCode()));
        }
        $responseBodyContents = $response->getBody()->getContents();
        $responseBodyContentsFromJson = json_decode($responseBodyContents, true, 512, JSON_THROW_ON_ERROR);
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
