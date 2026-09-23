<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Support;

use Guild\Grouper\GrouperClient;
use Guild\Grouper\GrouperConfiguration;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A real GrouperClient over Guzzle's MockHandler, recording every request it
 * sends, so tests can assert how often Grouper was actually asked.
 */
final class FakeGrouper
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $history = [];

    public readonly GrouperClient $client;

    /**
     * @param  list<ResponseInterface|Throwable>  $responses  Returned in order, one per request.
     */
    public function __construct(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $this->client = new GrouperClient(
            new GrouperConfiguration(
                serviceUrl: 'https://grouperws.apps.iu.edu/grouper-ws/servicesRest',
                username: 'svc',
                password: 'secret',
            ),
            new Client(['handler' => $stack]),
        );
    }

    public function requestCount(): int
    {
        return count($this->history);
    }

    /**
     * A successful getGroupsLite response.
     *
     * @param  array<string, string>  $groups  identifier => displayExtension
     */
    public static function membership(array $groups): ResponseInterface
    {
        $wsGroups = [];

        foreach ($groups as $identifier => $label) {
            $wsGroups[] = [
                'name' => $identifier,
                'displayName' => 'Indiana University:Roles:' . $label,
                'displayExtension' => $label,
                'uuid' => md5($identifier),
            ];
        }

        $result = ['resultMetadata' => ['resultCode' => 'SUCCESS', 'success' => 'T']];

        if ($wsGroups !== []) {
            $result['wsGroups'] = $wsGroups;
        }

        return new Response(200, [], json_encode(['WsGetGroupsLiteResult' => $result], JSON_THROW_ON_ERROR));
    }

    public static function unavailable(): ResponseInterface
    {
        return new Response(503, [], 'Service Unavailable');
    }

    public static function rejectedCredentials(): ResponseInterface
    {
        return new Response(401, [], 'Unauthorized');
    }
}
