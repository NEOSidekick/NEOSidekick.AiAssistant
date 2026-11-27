<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Controller;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Neos\Service\UserService;
use NEOSidekick\AiAssistant\Controller\DocumentNodeListApiController;
use NEOSidekick\AiAssistant\Service\DocumentNodeListExtractor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The document list endpoint hands the sort parameters of the request to the extractor, treats a
 * missing `sortBy` as "keep tree order", and answers a sort field the extractor refuses with a 400
 * that carries the reason.
 */
class DocumentNodeListApiControllerSortingTest extends TestCase
{
    /** @test */
    public function theSortParametersOfTheRequestReachTheExtractor(): void
    {
        $extractor = $this->createMock(DocumentNodeListExtractor::class);
        $extractor->expects(self::once())
            ->method('extract')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                'lastModificationDateTime',
                'asc'
            )
            ->willReturn(['documents' => []]);

        $this->createController($extractor, new ActionResponse())->listAction(
            sortBy: 'lastModificationDateTime',
            sortDirection: 'asc'
        );
    }

    /** @test */
    public function withoutSortByTheExtractorIsAskedForTreeOrder(): void
    {
        $extractor = $this->createMock(DocumentNodeListExtractor::class);
        $extractor->expects(self::once())
            ->method('extract')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                null,
                'desc'
            )
            ->willReturn(['documents' => []]);

        $this->createController($extractor, new ActionResponse())->listAction();
    }

    /** @test */
    public function aSortFieldTheExtractorRefusesIsABadRequestWithTheReason(): void
    {
        $extractor = $this->createMock(DocumentNodeListExtractor::class);
        $extractor->method('extract')->willThrowException(
            new \InvalidArgumentException('Cannot sort by "title". Allowed values for sortBy: creationDateTime')
        );
        $response = new ActionResponse();

        $body = $this->createController($extractor, $response)->listAction(sortBy: 'title');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Bad Request', 'message' => 'Cannot sort by "title". Allowed values for sortBy: creationDateTime'],
            json_decode($body, true, 512, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param DocumentNodeListExtractor&MockObject $extractor
     */
    private function createController(DocumentNodeListExtractor $extractor, ActionResponse $response): DocumentNodeListApiController
    {
        $userService = $this->createMock(UserService::class);
        $userService->method('getPersonalWorkspaceName')->willReturn('user-editor');
        $request = $this->createMock(ActionRequest::class);
        $request->method('getHttpRequest')->willReturn(new ServerRequest('GET', 'https://example.com/neosidekick/api/document-nodes'));

        $controller = $this->getMockBuilder(DocumentNodeListApiController::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $properties = ['response' => $response, 'request' => $request, 'extractor' => $extractor, 'userService' => $userService];
        foreach ($properties as $name => $value) {
            $property = new ReflectionProperty(DocumentNodeListApiController::class, $name);
            $property->setAccessible(true);
            $property->setValue($controller, $value);
        }

        return $controller;
    }
}
