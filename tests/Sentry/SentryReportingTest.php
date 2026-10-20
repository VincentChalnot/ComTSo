<?php

namespace App\Tests\Sentry;

use App\Tests\Support\AppWebTestCase;
use Sentry\Event;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\HttpClient\Request;
use Sentry\HttpClient\Response;
use Sentry\Options;
use Sentry\Serializer\PayloadSerializer;
use Sentry\State\HubInterface;
use Sentry\Transport\HttpTransport;
use Sentry\Transport\ResultStatus;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;

/**
 * Error reporting to Sentry (config/packages/sentry.yaml). The test environment swaps the HTTP transport for
 * {@see RecordingTransport}: nothing here can reach the network.
 */
class SentryReportingTest extends AppWebTestCase
{
    private const DSN = 'https://public@sentry.invalid/1';

    protected function setUp(): void
    {
        // No client yet: the DSN is read from the environment when the kernel boots (see bootWithDsn()).
    }

    protected function tearDown(): void
    {
        unset($_SERVER['SENTRY_DSN'], $_ENV['SENTRY_DSN']);
        putenv('SENTRY_DSN');
        parent::tearDown();
    }

    private function bootWithDsn(?string $dsn): void
    {
        if (null !== $dsn) {
            $_SERVER['SENTRY_DSN'] = $_ENV['SENTRY_DSN'] = $dsn;
        }
        $this->client = static::createClient();
    }

    /** @return list<Event> */
    private function sentEvents(): array
    {
        return static::getContainer()->get(RecordingTransport::class)->events();
    }

    private function failWith(\Throwable $exception): void
    {
        static::getContainer()->get('event_dispatcher')->addListener(KernelEvents::REQUEST, static function (RequestEvent $event) use ($exception): void {
            // Not the sub-request that renders the error page.
            if ($event->isMainRequest()) {
                throw $exception;
            }
        }, 1000);
    }

    public function testTheAppServesRequestsWithoutDsnAndTheSdkSendsNothing(): void
    {
        $this->bootWithDsn(null);

        $this->client->request('GET', '/health');
        self::assertResponseIsSuccessful();

        $options = static::getContainer()->get(HubInterface::class)->getClient()->getOptions();
        self::assertNull($options->getDsn(), 'an unset SENTRY_DSN leaves the SDK without DSN');

        // The real transport of the SDK skips events without DSN before it touches the HTTP client.
        $httpClient = new class implements HttpClientInterface {
            public function sendRequest(Request $request, Options $options): Response
            {
                throw new \LogicException('The SDK must not send anything without DSN.');
            }
        };
        $transport = new HttpTransport($options, $httpClient, new PayloadSerializer($options));
        self::assertSame(ResultStatus::skipped(), $transport->send(Event::createEvent())->getStatus());
    }

    public function testAnEmptyDsnIsTheSameAsNone(): void
    {
        $this->bootWithDsn('');

        $this->client->request('GET', '/health');
        self::assertResponseIsSuccessful();
        self::assertNull(static::getContainer()->get(HubInterface::class)->getClient()->getOptions()->getDsn());
    }

    public function testAnUnhandledExceptionIsReportedWithTheKernelEnvironment(): void
    {
        $this->bootWithDsn(self::DSN);
        $this->failWith(new \RuntimeException('boom'));

        $this->client->request('GET', '/health');

        self::assertResponseStatusCodeSame(500);
        self::assertCount(1, $this->sentEvents());
        $event = $this->sentEvents()[0];
        self::assertSame('test', $event->getEnvironment());
        self::assertSame(\RuntimeException::class, $event->getExceptions()[0]->getType());
    }

    public function testAnHttpExceptionOfFiveHundredAndAboveIsReported(): void
    {
        $this->bootWithDsn(self::DSN);
        $this->failWith(new ServiceUnavailableHttpException(null, 'down'));

        $this->client->request('GET', '/health');

        self::assertCount(1, $this->sentEvents());
    }

    /** @return iterable<string, array{\Closure(): \Throwable}> */
    public static function expectedClientErrors(): iterable
    {
        yield '404' => [static fn () => new NotFoundHttpException()];
        yield '403' => [static fn () => new AccessDeniedHttpException()];
        yield '401' => [static fn () => new UnauthorizedHttpException('Bearer')];
        yield '405 or any other 4xx' => [static fn () => new HttpException(405)];
        yield '422' => [static fn () => new UnprocessableEntityHttpException()];
        yield '429 rate limit' => [static fn () => new TooManyRequestsHttpException(60)];
        yield 'access denied' => [static fn () => new AccessDeniedException()];
        yield 'invalid CSRF token' => [static fn () => new InvalidCsrfTokenException()];
    }

    /** @param \Closure(): \Throwable $exception */
    #[\PHPUnit\Framework\Attributes\DataProvider('expectedClientErrors')]
    public function testExpectedClientErrorsAreNotReported(\Closure $exception): void
    {
        $this->bootWithDsn(self::DSN);
        $this->failWith($exception());

        $this->client->request('GET', '/health');

        self::assertSame([], $this->sentEvents());
    }

    public function testARealNotFoundAndARealForbiddenAreNotReported(): void
    {
        $this->bootWithDsn(self::DSN);
        $this->loginAs($this->createUser('alice'));

        $this->client->request('GET', '/no/such/page');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/admin/users/');
        self::assertResponseStatusCodeSame(403);

        self::assertSame([], $this->sentEvents());
    }

    public function testAnEventCarriesNothingAboutTheMemberOrTheRequestContent(): void
    {
        // Random values, so that the source lines the SDK attaches to the stack trace cannot contain them.
        $secrets = [];
        foreach (['query', 'body', 'header', 'cookie', 'member'] as $name) {
            $secrets[$name] = $name.'-'.bin2hex(random_bytes(6));
        }
        $ip = '203.0.113.'.random_int(10, 250);

        $this->bootWithDsn(self::DSN);
        $this->loginAs($this->createUser($secrets['member']));
        $this->failWith(new \RuntimeException('boom'));

        $this->client->request('POST', "/health?token={$secrets['query']}&u={$secrets['member']}", ['message' => $secrets['body']], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$secrets['header'],
            'HTTP_COOKIE' => 'MOCKSESSID='.$secrets['cookie'],
            'REMOTE_ADDR' => $ip,
        ]);

        self::assertCount(1, $this->sentEvents());
        $event = $this->sentEvents()[0];
        self::assertNull($event->getUser(), 'no member identity, no IP address');

        $options = static::getContainer()->get(HubInterface::class)->getClient()->getOptions();
        $json = (new PayloadSerializer($options))->serialize($event);
        foreach ([...$secrets, $ip, 'example.test'] as $private) {
            self::assertStringNotContainsString($private, $json, "the event leaks {$private}");
        }
        self::assertArrayNotHasKey('cookies', $event->getRequest());
        self::assertArrayNotHasKey('headers', $event->getRequest());
        self::assertArrayNotHasKey('data', $event->getRequest());
        self::assertArrayNotHasKey('query_string', $event->getRequest());
    }
}
