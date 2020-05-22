<?php

namespace App\Tests\Controller;

use App\Entity\User;
use MarcW\RssWriter\Bridge\Symfony\HttpFoundation\RssStreamedResponse;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Guard\Token\PostAuthenticationGuardToken;

class DefaultControllerTest extends WebTestCase
{
    /** @var \Symfony\Bundle\FrameworkBundle\KernelBrowser */
    private $client = null;

    public function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testIndexNotLoggedIn(): void
    {
        $crawler = $this->client->request('GET', '/');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Bandito.re', $crawler->filter('a.pure-menu-heading')->text());
    }

    public function testIndexLoggedIn(): void
    {
        /** @var User */
        $user = self::$container->get('banditore.repository.user.test')->find(123);

        $this->logIn($user);
        $this->client->request('GET', '/');

        /** @var \Symfony\Component\HttpFoundation\RedirectResponse */
        $response = $this->client->getResponse();
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('dashboard', $response->getTargetUrl());
    }

    public function testConnect(): void
    {
        $this->client->request('GET', '/connect');

        /** @var \Symfony\Component\HttpFoundation\RedirectResponse */
        $response = $this->client->getResponse();
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('https://github.com/login/oauth/authorize?', $response->getTargetUrl());
    }

    public function testConnectWithLoggedInUser(): void
    {
        /** @var User */
        $user = self::$container->get('banditore.repository.user.test')->find(123);

        $this->logIn($user);
        $this->client->request('GET', '/connect');

        /** @var \Symfony\Component\HttpFoundation\RedirectResponse */
        $response = $this->client->getResponse();
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('dashboard', $response->getTargetUrl());
    }

    public function testDashboardNotLoggedIn(): void
    {
        $this->client->request('GET', '/dashboard');

        /** @var \Symfony\Component\HttpFoundation\RedirectResponse */
        $response = $this->client->getResponse();
        $this->assertSame(302, $response->getStatusCode());
    }

    public function testDashboard(): void
    {
        /** @var User */
        $user = self::$container->get('banditore.repository.user.test')->find(123);

        $this->logIn($user);
        $crawler = $this->client->request('GET', '/dashboard');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $menu = $crawler->filter('.menu-wrapper')->text();
        $this->assertStringContainsString('View it on GitHub', $menu, 'Link to GitHub is here');
        $this->assertStringContainsString('Logout (admin)', $menu, 'Info about logged in user is here');

        $aside = $crawler->filter('aside.feed')->text();
        $this->assertStringContainsString('your feed link', $aside, 'Feed link is here');

        $table = $crawler->filter('table')->text();
        $this->assertStringContainsString('test/test', $table, 'Repo test/test exist in a table');
        $this->assertStringContainsString('ago', $table, 'Date is translated and ok');
    }

    public function testDashboardPageTooHigh(): void
    {
        /** @var User */
        $user = self::$container->get('banditore.repository.user.test')->find(123);

        $this->logIn($user);
        $crawler = $this->client->request('GET', '/dashboard?page=20000');

        /** @var \Symfony\Component\HttpFoundation\RedirectResponse */
        $response = $this->client->getResponse();
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('dashboard', $response->getTargetUrl());
    }

    public function testDashboardBadPage(): void
    {
        /** @var User */
        $user = self::$container->get('banditore.repository.user.test')->find(123);

        $this->logIn($user);
        $crawler = $this->client->request('GET', '/dashboard?page=dsdsds');

        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testRss(): void
    {
        /** @var User */
        $user = self::$container->get('banditore.repository.user.test')->find(123);
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

    public function testStats(): void
    {
        $crawler = $this->client->request('GET', '/stats');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testStatus(): void
    {
        $crawler = $this->client->request('GET', '/status');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertTrue($data['is_fresh']);
    }

    private function logIn(User $user): void
    {
        $session = self::$container->get('session');

        $firewall = 'main';

        $token = new PostAuthenticationGuardToken($user, $firewall, ['ROLE_ADMIN']);
        $session->set('_security_' . $firewall, serialize($token));
        $session->save();

        $cookie = new Cookie($session->getName(), $session->getId());
        $this->client->getCookieJar()->set($cookie);
    }
}
