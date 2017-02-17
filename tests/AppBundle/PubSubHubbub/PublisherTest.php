<?php

namespace Tests\AppBundle\PubSubHubbub;

use AppBundle\PubSubHubbub\Publisher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class PublisherTest extends \PHPUnit_Framework_TestCase
{
    private $router;

    public function setUp()
    {
        $routes = new RouteCollection();
        $routes->add('rss_user', new Route('/{uuid}.atom'));

        $sc = $this->getServiceContainer($routes);

        $this->router = new Router($sc, 'rss_user');
    }

    public function testNoHubDefined()
    {
        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $client = new Client();

        $publisher = new Publisher('', $this->router, $client, $userRepository, 'banditore.com', 'http');

        $res = $publisher->pingHub([1]);

        // the hub url is invalid, so it will be generate an error and return false
        $this->assertFalse($res);
    }

    public function testBadResponse()
    {
        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();
        $userRepository->expects($this->once())
            ->method('findByRepoIds')
            ->with([123])
            ->will($this->returnValue([['uuid' => '7fc8de31-5371-4f0a-b606-a7e164c41d46']]));

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

    public function testGoodResponse()
    {
        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();
        $userRepository->expects($this->once())
            ->method('findByRepoIds')
            ->with([123])
            ->will($this->returnValue([['uuid' => '7fc8de31-5371-4f0a-b606-a7e164c41d46']]));

        $mock = new MockHandler([
            new Response(204),
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $publisher = new Publisher('http://pubsubhubbub.io', $this->router, $client, $userRepository, 'banditore.com', 'http');
        $res = $publisher->pingHub([123]);

        $this->assertTrue($res);
    }

    public function testUrlGeneration()
    {
        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();
        $userRepository->expects($this->once())
            ->method('findByRepoIds')
            ->with([123])
            ->will($this->returnValue([['uuid' => '7fc8de31-5371-4f0a-b606-a7e164c41d46']]));

        $method = new \ReflectionMethod(
          'AppBundle\PubSubHubbub\Publisher', 'retrieveFeedUrls'
        );

        $method->setAccessible(true);

        $urls = $method->invoke(
            new Publisher('http://pubsubhubbub.io', $this->router, new Client(), $userRepository, 'banditore.com', 'http'),
            [123]
        );

        $this->assertSame(['http://banditore.com/7fc8de31-5371-4f0a-b606-a7e164c41d46.atom'], $urls);
    }

    /**
     * @see \Symfony\Bundle\FrameworkBundle\Tests\Routing\RouterTest
     *
     * @param RouteCollection $routes
     *
     * @return \Symfony\Component\DependencyInjection\Container
     */
    private function getServiceContainer(RouteCollection $routes)
    {
        $loader = $this->getMockBuilder('Symfony\Component\Config\Loader\LoaderInterface')->getMock();

        $loader
            ->expects($this->any())
            ->method('load')
            ->will($this->returnValue($routes))
        ;

        $sc = $this->getMockBuilder('Symfony\\Component\\DependencyInjection\\Container')->setMethods(['get'])->getMock();

        $sc
            ->expects($this->any())
            ->method('get')
            ->will($this->returnValue($loader))
        ;

        return $sc;
    }
}
