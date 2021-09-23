<?php

namespace App\Tests\Controller;

use App\Entity\User;
use MarcW\RssWriter\Bridge\Symfony\HttpFoundation\RssStreamedResponse;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DefaultControllerTest extends WebTestCase
{
    /** @var \Symfony\Bundle\FrameworkBundle\KernelBrowser */
    private $client = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testIndexNotLoggedIn(): void
    {
        $crawler = $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('a.pure-menu-heading', 'Bandito.re');
    }

    public function testIndexLoggedIn(): void
    {
        /** @var User */
        $user = self::$container->get('banditore.repository.user.test')->find(123);

        $this->client->loginUser($user);
        $this->client->request('GET', '/');

        $this->assertResponseRedirects('/dashboard', 302);
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

        $this->client->loginUser($user);
        $this->client->request('GET', '/connect');

        $this->assertResponseRedirects('/dashboard', 302);
    }

    public function testDashboardNotLoggedIn(): void
    {
        $this->client->request('GET', '/dashboard');

        $this->assertResponseRedirects('/', 302);
    }

    public function testDashboard(): void
    {
        /** @var User */
        $user = self::$container->get('banditore.repository.user.test')->find(123);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();

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

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/dashboard?page=20000');

        $this->assertResponseRedirects('/dashboard', 302);
    }

    public function testDashboardBadPage(): void
    {
        /** @var User */
        $user = self::$container->get('banditore.repository.user.test')->find(123);

        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard?page=dsdsds');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testRss(): void
    {
        /** @var User */
        $user = self::$container->get('banditore.repository.user.test')->find(123);
        $crawler = $this->client->request('GET', '/' . $user->getUuid() . '.atom');

        $this->assertResponseIsSuccessful();
        $this->assertInstanceOf(RssStreamedResponse::class, $this->client->getResponse());

        $this->assertSelectorTextContains('channel>title', 'New releases from starred repo of admin');
        $this->assertSelectorTextContains('channel>description', 'Here are all the new releases from all repos starred by admin');

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

        $this->assertResponseIsSuccessful();
    }

    public function testStatus(): void
    {
        $crawler = $this->client->request('GET', '/status');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertTrue($data['is_fresh']);
    }
}
