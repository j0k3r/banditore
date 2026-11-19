<?php

namespace App\Tests\Controller;

use App\Entity\Repo;
use App\Entity\Star;
use App\Entity\User;
use App\Repository\StarRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use MarcW\RssWriter\Bridge\Symfony\HttpFoundation\RssStreamedResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\RedirectResponse;

class DefaultControllerTest extends WebTestCase
{
    /** @var KernelBrowser */
    private $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE star SET ignored_in_feed = 0');
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE user SET hide_prereleases = 0');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE version SET prerelease = 0');
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE user SET hide_prereleases = 0');
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM star WHERE user_id IN (321, 322)');
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM user WHERE id IN (321, 322)');
        parent::tearDown();
    }

    public function testIndexNotLoggedIn(): void
    {
        $crawler = $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('a.pure-menu-heading', 'Bandito.re');
        $this->assertSelectorNotExists('a[href$="/config"]');
        $this->assertSelectorNotExists('.pure-menu-link[aria-current="page"]');
        $this->assertSelectorTextContains('.footer a[href="https://github.com/j0k3r/banditore"]', 'GitHub');
    }

    public function testIndexLoggedIn(): void
    {
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(123);

        $this->client->loginUser($user);
        $this->client->request('GET', '/');

        $this->assertResponseRedirects('/dashboard', 302);
    }

    public function testConnect(): void
    {
        $this->client->request('GET', '/connect');

        /** @var RedirectResponse */
        $response = $this->client->getResponse();
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('https://github.com/login/oauth/authorize?', $response->getTargetUrl());
    }

    public function testConnectWithLoggedInUser(): void
    {
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(123);

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
        $user = self::getContainer()->get(UserRepository::class)->find(123);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();

        $menu = $crawler->filter('.pure-menu-list a')->each(static fn (Crawler $link) => $link->text());
        $this->assertSame(['Dashboard', 'Stats', 'Config', 'Logout (admin)'], $menu);
        $this->assertSelectorCount(1, '.pure-menu-link[aria-current="page"]');
        $this->assertSelectorTextContains('.pure-menu-link[aria-current="page"]', 'Dashboard');
        $this->assertSelectorNotExists('input[name="hide_prereleases"]');

        $aside = $crawler->filter('aside.feed')->text();
        $this->assertStringContainsString('your feed link', $aside, 'Feed link is here');

        $table = $crawler->filter('table')->text();
        $this->assertStringContainsString('test/test', $table, 'Repo test/test exist in a table');
        $this->assertStringContainsString('Exclude', $table, 'Feed toggle is available from the dashboard');
        $this->assertStringContainsString('ago', $table, 'Date is translated and ok');
    }

    public function testConfigPrereleasePreferenceAppliesToDashboardAndFeed(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE version SET prerelease = 1 WHERE repo_id = 666');
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(123);
        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('table', 'test/test');

        $crawler = $this->client->request('GET', '/config');
        $this->assertCheckboxNotChecked('hide_prereleases');
        $form = $crawler->filter('form.config-preferences')->form();
        $form['hide_prereleases']->tick();
        $this->client->submit($form);
        $this->assertResponseRedirects('/config');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert.success', 'hidden from your dashboard and RSS feed');
        $this->assertCheckboxChecked('hide_prereleases');

        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextNotContains('table', 'test/test');
        $this->assertSelectorTextContains('table', 'symfony/symfony');
        $this->client->request('GET', '/' . $user->getUuid() . '.atom');
        $this->assertSelectorTextNotContains('channel', 'test/test');
        $this->assertSelectorTextContains('item>title', 'symfony/symfony');

        $crawler = $this->client->request('GET', '/config');
        $form = $crawler->filter('form.config-preferences')->form();
        $form['hide_prereleases']->untick();
        $this->client->submit($form);
        $this->client->followRedirect();
        $this->assertCheckboxNotChecked('hide_prereleases');
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('table', 'test/test');
        $this->client->request('GET', '/' . $user->getUuid() . '.atom');
        $this->assertSelectorTextContains('item>title', 'test/test');
    }

    #[DataProvider('configMethods')]
    public function testConfigRequiresAuthentication(string $method): void
    {
        $this->client->request($method, '/config', ['hide_prereleases' => '1']);

        $this->assertResponseRedirects('/');
    }

    public static function configMethods(): iterable
    {
        yield 'view' => ['GET'];
        yield 'save' => ['POST'];
    }

    public function testPrereleasePreferenceRequiresValidCsrfToken(): void
    {
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(123);
        $this->client->loginUser($user);
        $this->client->request('POST', '/config', ['hide_prereleases' => '1', '_token' => 'invalid']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertFalse(self::getContainer()->get(UserRepository::class)->find(123)->getHidePrereleases());
    }

    #[DataProvider('prereleasePreferences')]
    public function testDashboardShowsSamplesBeforeTheFirstSync(bool $hidePrereleases): void
    {
        $user = new User();
        $user->setId($hidePrereleases ? 322 : 321);
        $user->setUsername('not-synced');
        $user->setAvatar('http://localhost/avatar.jpg');
        $user->setAccessToken('test-token');
        $user->setHidePrereleases($hidePrereleases);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.content', 'Here are some sample releases');
        $this->assertSelectorTextContains('table', 'sample/sample');
        $this->assertSelectorCount($hidePrereleases ? 1 : 2, 'table tbody tr');
        $this->assertSelectorCount($hidePrereleases ? 0 : 1, '.label_prerelease');
        $this->client->request('GET', '/' . $user->getUuid() . '.atom');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('item');
    }

    public static function prereleasePreferences(): iterable
    {
        yield 'pre-releases included' => [false];
        yield 'pre-releases hidden' => [true];
    }

    public function testDashboardWithOnlyHiddenPrereleases(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('UPDATE version SET prerelease = 1');
        $connection->executeStatement('UPDATE user SET hide_prereleases = 1 WHERE id = 123');
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(123);
        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.content', 'No releases to show with pre-releases hidden');
        $this->assertSelectorNotExists('table');
        $this->assertSelectorExists('.content a[href="/config"]');
        $this->assertSelectorNotExists('input[name="hide_prereleases"]');
        $this->client->request('GET', '/' . $user->getUuid() . '.atom');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('item');
    }

    public function testConfigWithNoExcludedRepositories(): void
    {
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(123);
        $this->client->loginUser($user);
        $this->client->request('GET', '/config');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.content-head', 'Configuration');
        $this->assertSelectorCount(1, '.pure-menu-link[aria-current="page"]');
        $this->assertSelectorTextContains('.pure-menu-link[aria-current="page"]', 'Config');
        $this->assertSelectorTextContains('.content', 'No repositories are excluded from your RSS feed.');
        $this->assertCheckboxNotChecked('hide_prereleases');
    }

    public function testConfigListsOnlyTheUsersExcludedRepositories(): void
    {
        $this->createOtherUserWithExcludedRepo();
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE star SET ignored_in_feed = 1 WHERE user_id = 123 AND repo_id = 666');
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(123);
        $this->client->loginUser($user);
        $this->client->request('GET', '/config');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('table.pure-table.pure-table-rwd.config-repositories');
        $this->assertSelectorCount(1, '.config-repositories tbody tr');
        $this->assertSelectorExists('.config-repositories tbody tr.pure-table-odd');
        $this->assertSelectorExists('.config-repositories .feed-toggle-button');
        $this->assertSelectorTextContains('.config-repositories', 'test/test');
        $this->assertSelectorTextNotContains('.config-repositories', 'symfony/symfony');
        $this->assertSelectorTextNotContains('.config-repositories', 'j0k3r/graby');

        self::getContainer()->get(Connection::class)->executeStatement('UPDATE star SET ignored_in_feed = 1 WHERE user_id = 123 AND repo_id = 555');
        $crawler = $this->client->request('GET', '/config');
        $names = $crawler->filter('.config-repositories .repo-name')->each(static fn (Crawler $node) => $node->text());
        $this->assertSame(['symfony/symfony', 'test/test'], $names);
    }

    public function testConfigCanIncludeAnExcludedRepositoryAgain(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE star SET ignored_in_feed = 1 WHERE user_id = 123 AND repo_id = 666');
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(123);
        $user->setHidePrereleases(true);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/config');
        $form = $crawler->selectButton('Include again')->form();
        $this->client->submit($form);

        $this->assertResponseRedirects('/config');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert.success', 'test/test is now included again in your RSS feed.');
        $this->assertSelectorNotExists('.config-repositories');
        $this->assertCheckboxChecked('hide_prereleases');
        $star = self::getContainer()->get(StarRepository::class)->findOneByUserAndRepo(123, 666);
        $this->assertNotNull($star);
        $this->assertFalse($star->isIgnoredInFeed());
        $this->client->request('GET', '/' . $user->getUuid() . '.atom');
        $this->assertSelectorTextContains('item>title', 'test/test');
    }

    public function testRepoFeedToggleRequiresValidCsrfToken(): void
    {
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(123);
        $this->client->loginUser($user);
        $this->client->request('POST', '/dashboard/repositories/666/feed', ['ignore_in_feed' => '1']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertFalse(self::getContainer()->get(StarRepository::class)->findOneByUserAndRepo(123, 666)->isIgnoredInFeed());
    }

    public function testRepoFeedToggleCannotChangeAnotherUsersSubscription(): void
    {
        $this->createOtherUserWithExcludedRepo();
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(123);
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/dashboard');
        $form = $crawler->filter('form[action="/dashboard/repositories/666/feed"]')->first()->form();
        $this->client->request('POST', '/dashboard/repositories/444/feed', [
            '_token' => $form['_token']->getValue(),
            'ignore_in_feed' => '0',
        ]);

        $this->assertResponseStatusCodeSame(404);
        $this->assertTrue(self::getContainer()->get(StarRepository::class)->findOneByUserAndRepo(321, 444)->isIgnoredInFeed());
    }

    public function testDashboardRepoFeedToggle(): void
    {
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(123);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/dashboard');
        $form = $crawler->filter('form[action="/dashboard/repositories/666/feed"]')->first()->form();
        $this->client->submit($form);

        $this->assertResponseRedirects('/dashboard', 302);

        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert.success', 'test/test is now ignored in your RSS feed.');

        $star = self::getContainer()->get(StarRepository::class)->findOneByUserAndRepo(123, 666);

        $this->assertNotNull($star);
        $this->assertTrue($star->isIgnoredInFeed());
    }

    public function testDashboardPageTooHigh(): void
    {
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(123);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/dashboard?page=20000');

        $this->assertResponseRedirects('/dashboard', 302);
    }

    public function testDashboardBadPage(): void
    {
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(123);

        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard?page=dsdsds');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testRss(): void
    {
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(123);
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
        $cache = new ArrayAdapter();
        self::getContainer()->set('cache.app', $cache);

        $this->client->request('GET', '/stats');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(1, '.pure-menu-link[aria-current="page"]');
        $this->assertSelectorTextContains('.pure-menu-link[aria-current="page"]', 'Stats');
        $item = $cache->getItem('global_stats_v1');
        $this->assertTrue($item->isHit());
        $this->assertEqualsWithDelta(microtime(true) + 900, $item->getMetadata()['expiry'], 5);
        $this->assertSame(3, $item->get()['counters']['nbRepos']);
        $this->assertNotEmpty($item->get()['mostReleases']);
        $this->assertNotEmpty($item->get()['lastestReleases']);
    }

    public function testStatsUsesCachedData(): void
    {
        $cache = new ArrayAdapter();
        $cache->get('global_stats_v1', static fn () => [
            'counters' => [
                'nbRepos' => 98765,
                'nbReleases' => 123456,
                'avgReleasePerRepo' => 1.25,
                'avgStarPerUser' => 42,
            ],
            'mostReleases' => [],
            'lastestReleases' => [],
        ]);
        self::getContainer()->set('cache.app', $cache);

        $this->client->request('GET', '/stats');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', '98765');
        $this->assertSelectorTextContains('table', '123456');
    }

    public function testStatus(): void
    {
        $crawler = $this->client->request('GET', '/status');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertTrue($data['is_fresh']);
    }

    private function createOtherUserWithExcludedRepo(): void
    {
        $user = new User();
        $user->setId(321);
        $user->setUsername('other-user');
        $user->setAvatar('http://localhost/avatar.jpg');
        $user->setAccessToken('test-token');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $star = new Star($user, $entityManager->getReference(Repo::class, 444));
        $star->setIgnoredInFeed(true);
        $entityManager->persist($star);
        $entityManager->flush();
    }
}
