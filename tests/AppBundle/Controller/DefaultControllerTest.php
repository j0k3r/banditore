<?php

namespace Tests\AppBundle\Controller;

use AppBundle\Entity\User;
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

        $header = $crawler->filter('.header')->text();
        $this->assertContains('View it on Github', $header, 'Link to Github is here');
        $this->assertContains('Logged in as admin', $header, 'Info about logged in user is here');
        $this->assertContains('Your RSS feed', $header, 'RSS feed is here');

        $table = $crawler->filter('table')->text();
        $this->assertContains('test/test', $table, 'Repo test/test exist in a table');
        $this->assertContains('First release (v1.0.0)', $table, 'First release of test/test exist in a table');
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
