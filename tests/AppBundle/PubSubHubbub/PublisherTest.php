<?php

namespace Tests\AppBundle\PubSubHubbub;

use AppBundle\PubSubHubbub\Publisher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PublisherTest extends \PHPUnit_Framework_TestCase
{
    public function testNoHubDefined()
    {
        $router = $this->getMockBuilder('Symfony\Bundle\FrameworkBundle\Routing\Router')
            ->disableOriginalConstructor()
            ->getMock();
        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $client = new Client();

        $publisher = new Publisher('', $router, $client, $userRepository);

        $res = $publisher->pingHub([1]);

        // the hub url is invalid, so it will be generate an error and return false
        $this->assertFalse($res);
    }

    public function testBadResponse()
    {
        $router = $this->getMockBuilder('Symfony\Bundle\FrameworkBundle\Routing\Router')
            ->disableOriginalConstructor()
            ->getMock();
        $router->expects($this->once())
            ->method('generate')
            ->with('rss_user', ['uuid' => '7fc8de31-5371-4f0a-b606-a7e164c41d46'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->will($this->returnValue('http://bandito.re/7fc8de31-5371-4f0a-b606-a7e164c41d46.atom'));

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

        $publisher = new Publisher('http://pubsubhubbub.io', $router, $client, $userRepository);
        $res = $publisher->pingHub([123]);

        // the response is bad, so it will return false
        $this->assertFalse($res);
    }

    public function testGoodResponse()
    {
        $router = $this->getMockBuilder('Symfony\Bundle\FrameworkBundle\Routing\Router')
            ->disableOriginalConstructor()
            ->getMock();
        $router->expects($this->once())
            ->method('generate')
            ->with('rss_user', ['uuid' => '7fc8de31-5371-4f0a-b606-a7e164c41d46'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->will($this->returnValue('http://bandito.re/7fc8de31-5371-4f0a-b606-a7e164c41d46.atom'));

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

        $publisher = new Publisher('http://pubsubhubbub.io', $router, $client, $userRepository);
        $res = $publisher->pingHub([123]);

        $this->assertTrue($res);
    }
}
