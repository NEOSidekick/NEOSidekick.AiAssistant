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
use NEOSidekick\AiAssistant\Exception\GetMostRelevantInternalSeoLinksApiException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;

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

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $systemLogger;

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
     * @throws GetMostRelevantInternalSeoLinksApiException
     */
    public function getMostRelevantInternalSeoLinksByHosts(array $hosts, string $interfaceLanguage): array
    {
        $request = new ServerRequest('POST', $this->apiDomain . '/api/v1/find-most-relevant-internal-seo-links');
        $request = $request->withAddedHeader('Accept', 'application/json');
        $request = $request->withAddedHeader('Authorization', 'Bearer ' . $this->apiKey);
        $request = $request->withAddedHeader('Content-Type', 'application/json');
        /** @var ServerRequest $request */
        $request = $request->withBody(self::streamFor(json_encode(['language' => $interfaceLanguage, 'uris' => $hosts], JSON_THROW_ON_ERROR)));
        try {
            $response = $this->browser->sendRequest($request);
        } catch (CurlEngineException $e) {
            // cURL error 28 stands for operation timed out aka the API did not answer within the given time
            if (str_starts_with($e->getMessage(), 'cURL reported error code 28')) {
                throw new GetMostRelevantInternalSeoLinksApiException('Analyzing your website with NEOSidekick took too long. Please try again, typically this succeeds the second time. <br><br>We are here to help, you can reach us at <a style="text-decoration: underline;" href="mailto:support@neosidekick.com">support@neosidekick.com</a>');
            }

            throw $e;
        }

        $responseBodyContents = $response->getBody()->getContents();
        $responseBodyContentsFromJson = json_decode($responseBodyContents, true);
        if ($responseBodyContentsFromJson === null) {
            throw new GetMostRelevantInternalSeoLinksApiException(sprintf('Invalid JSON response from NEOSidekick find-most-relevant-internal-seo-links API: %s<br><br>We are here to help, you can reach us at <a style="text-decoration: underline;" href="mailto:support@neosidekick.com">support@neosidekick.com</a>', $responseBodyContents));
        }
        if ($response->getStatusCode() !== 200) {
            if (isset($responseBodyContentsFromJson['message'])) {
                throw new GetMostRelevantInternalSeoLinksApiException($responseBodyContentsFromJson['message']);
            }
            throw new GetMostRelevantInternalSeoLinksApiException(sprintf('Invalid status code from NEOSidekick API %s and message: %s<br><br>We are here to help, you can reach us at <a style="text-decoration: underline;" href="mailto:support@neosidekick.com">support@neosidekick.com</a>', $response->getStatusCode(), $responseBodyContents));
        }
        if (!isset($responseBodyContentsFromJson['data']['uris'])) {
            throw new GetMostRelevantInternalSeoLinksApiException(sprintf('Invalid response from NEOSidekick API, "data.uris" is missing in response: %s<br><br>We are here to help, you can reach us at <a style="text-decoration: underline;" href="mailto:support@neosidekick.com">support@neosidekick.com</a>', $responseBodyContents));
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
    /**
     * Sends a batch request to the modules batch API endpoint
     *
     * @param array $payload The payload to send to the batch API
     * @return void
     */
    public function sendBatchModuleRequest(array $payload): void
    {
        $request = new ServerRequest('POST', $this->apiDomain . '/api/v1/modules/batch');
        $request = $request->withAddedHeader('Accept', 'application/json');
        $request = $request->withAddedHeader('Authorization', 'Bearer ' . $this->apiKey);
        $request = $request->withAddedHeader('Content-Type', 'application/json');

        try {
            /** @var ServerRequest $request */
            $request = $request->withBody(self::streamFor(json_encode($payload, JSON_THROW_ON_ERROR)));
            $response = $this->browser->sendRequest($request);

            if ($response->getStatusCode() !== 200) {
                $this->systemLogger->error('Batch module request failed with status code: ' . $response->getStatusCode(), [
                    'packageKey' => 'NEOSidekick.AiAssistant',
                    'response' => $response->getBody()->getContents()
                ]);
            }
        } catch (ClientExceptionInterface | \Exception $e) {
            $this->systemLogger->error('Batch module request failed: ' . $e->getMessage(), [
                'packageKey' => 'NEOSidekick.AiAssistant',
                'exception' => $e
            ]);
        }
    }
}
