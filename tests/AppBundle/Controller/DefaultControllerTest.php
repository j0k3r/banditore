<?php

namespace Tests\AppBundle\Controller;

use AppBundle\Entity\User;
use MarcW\RssWriter\Bridge\Symfony\HttpFoundation\RssStreamedResponse;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Guard\Token\PostAuthenticationGuardToken;

class DefaultControllerTest extends WebTestCase
{
    private $client = null;

    public function setUp()
    {
        $this->client = static::createClient();
    }

    public function testIndexNotLoggedIn()
    {
        $crawler = $this->client->request('GET', '/');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertContains('Bandito.re', $crawler->filter('a.pure-menu-heading')->text());
    }

    public function testIndexLoggedIn()
    {
        $user = $this->client->getContainer()->get('banditore.repository.user')->find(123);

        $this->logIn($user);
        $this->client->request('GET', '/');

        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertContains('dashboard', $this->client->getResponse()->getTargetUrl());
    }

    public function testConnect()
    {
        $this->client->request('GET', '/connect');

        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertContains('https://github.com/login/oauth/authorize?scope=user%2Crepo', $this->client->getResponse()->getTargetUrl());
    }

    public function testConnectWithLoggedInUser()
    {
        $user = $this->client->getContainer()->get('banditore.repository.user')->find(123);

        $this->logIn($user);
        $this->client->request('GET', '/connect');

        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertContains('dashboard', $this->client->getResponse()->getTargetUrl());
    }

    public function testDashboardNotLoggedIn()
    {
        $this->client->request('GET', '/dashboard');

        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
    }

    public function testDashboard()
    {
        $user = $this->client->getContainer()->get('banditore.repository.user')->find(123);

        $this->logIn($user);
        $crawler = $this->client->request('GET', '/dashboard');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $menu = $crawler->filter('.menu-wrapper')->text();
        $this->assertContains('View it on Github', $menu, 'Link to Github is here');
        $this->assertContains('Logout (admin)', $menu, 'Info about logged in user is here');

        $aside = $crawler->filter('aside.feed')->text();
        $this->assertContains('your RSS feed', $aside, 'RSS feed is here');

        $table = $crawler->filter('table')->text();
        $this->assertContains('test/test', $table, 'Repo test/test exist in a table');
    }

    public function testDashboardPageTooHigh()
    {
        $user = $this->client->getContainer()->get('banditore.repository.user')->find(123);

        $this->logIn($user);
        $crawler = $this->client->request('GET', '/dashboard?page=20000');

        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertContains('dashboard', $this->client->getResponse()->getTargetUrl());
    }

    public function testDashboardBadPage()
    {
        $user = $this->client->getContainer()->get('banditore.repository.user')->find(123);

        $this->logIn($user);
        $crawler = $this->client->request('GET', '/dashboard?page=dsdsds');

        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testRss()
    {
        $user = $this->client->getContainer()->get('banditore.repository.user')->find(123);
        $crawler = $this->client->request('GET', '/' . $user->getUuid() . '.atom');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertInstanceOf(RssStreamedResponse::class, $this->client->getResponse());

        $this->assertSame('New releases from starred repo of admin', $crawler->filter('channel>title')->text());
        $this->assertSame('Here are all the new releases from all repos starred by admin', $crawler->filter('channel>description')->text());
        $this->assertSame('http://0.0.0.0/avatar.jpg', $crawler->filterXPath('//webfeeds:icon')->text());
        $this->assertSame('10556B', $crawler->filterXPath('//webfeeds:accentColor')->text());

        $link = $crawler->filterXPath('//atom:link');
        $this->assertSame('http://localhost/' . $user->getUuid() . '.atom', $link->getNode(0)->getAttribute('href'));
        $this->assertSame('http://pubsubhubbub.appspot.com/', $link->getNode(1)->getAttribute('href'));

        $this->assertSame('http://localhost/' . $user->getUuid() . '.atom', $crawler->filter('channel>link')->text());
        $this->assertSame('test/test 1.0.0', $crawler->filter('item>title')->text());
    }

    public function testStats()
    {
        $crawler = $this->client->request('GET', '/stats');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    private function logIn(User $user)
    {
        $session = $this->client->getContainer()->get('session');

        $firewall = 'main';

        $token = new PostAuthenticationGuardToken($user, $firewall, ['ROLE_ADMIN']);
        $session->set('_security_' . $firewall, serialize($token));
        $session->save();

        $cookie = new Cookie($session->getName(), $session->getId());
        $this->client->getCookieJar()->set($cookie);
    }
}
