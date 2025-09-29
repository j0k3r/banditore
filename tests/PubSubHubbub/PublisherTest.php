<?php

namespace App\Tests\PubSubHubbub;

use App\PubSubHubbub\Publisher;
use App\Repository\UserRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class PublisherTest extends TestCase
{
    /** @var Router */
    private $router;

    protected function setUp(): void
    {
        $routes = new RouteCollection();
        $routes->add('rss_user', new Route('/{uuid}.atom'));

        $sc = $this->getServiceContainer($routes);

        $this->router = new Router($sc, 'rss_user');
    }

    public function testNoHubDefined(): void
    {
        $userRepository = $this->getMockBuilder(UserRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $client = new Client();

        $publisher = new Publisher('', $this->router, $client, $userRepository, 'banditore.com', 'http');

        $res = $publisher->pingHub([1]);

        // the hub url is invalid, so it will be generate an error and return false
        $this->assertFalse($res);
    }

    public function testBadResponse(): void
    {
        $userRepository = $this->getMockBuilder(UserRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $userRepository->expects($this->once())
            ->method('findByRepoIds')
            ->with([123])
            ->willReturn([['uuid' => '7fc8de31-5371-4f0a-b606-a7e164c41d46']]);

        $mock = new MockHandler([
            new Response(500),
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $publisher = new Publisher('http://pubsubhubbub.io', $this->router, $client, $userRepository, 'banditore.com', 'http');
        $res = $publisher->pingHub([123]);

        // the response is bad, so it will return false
        $this->assertFalse($res);
    }

    public function testGoodResponse(): void
    {
        $userRepository = $this->getMockBuilder(UserRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $userRepository->expects($this->once())
            ->method('findByRepoIds')
            ->with([123])
            ->willReturn([['uuid' => '7fc8de31-5371-4f0a-b606-a7e164c41d46']]);

        $mock = new MockHandler([
            new Response(204),
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $publisher = new Publisher('http://pubsubhubbub.io', $this->router, $client, $userRepository, 'banditore.com', 'http');
        $res = $publisher->pingHub([123]);

        $this->assertTrue($res);
    }

    public function testUrlGeneration(): void
    {
        $userRepository = $this->getMockBuilder(UserRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $userRepository->expects($this->once())
            ->method('findByRepoIds')
            ->with([123])
            ->willReturn([['uuid' => '7fc8de31-5371-4f0a-b606-a7e164c41d46']]);

        $method = new \ReflectionMethod(
            Publisher::class, 'retrieveFeedUrls'
        );

        $urls = $method->invoke(
            new Publisher('http://pubsubhubbub.io', $this->router, new Client(), $userRepository, 'banditore.com', 'http'),
            [123]
        );

        $this->assertSame(['http://banditore.com/7fc8de31-5371-4f0a-b606-a7e164c41d46.atom'], $urls);
    }

    /**
     * @see \Symfony\Bundle\FrameworkBundle\Tests\Routing\RouterTest
     */
    private function getServiceContainer(RouteCollection $routes): Container
    {
        $loader = $this->getMockBuilder(LoaderInterface::class)->getMock();

        $loader
            ->expects($this->any())
            ->method('load')
            ->willReturn($routes)
        ;

        $sc = $this->getMockBuilder(Container::class)->onlyMethods(['get'])->getMock();

        $sc
            ->expects($this->any())
            ->method('get')
            ->willReturn($loader)
        ;

        return $sc;
    }
}
