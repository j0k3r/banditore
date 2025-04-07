<?php

namespace App\Controller;

use App\Entity\User;
use App\Pagination\Exception\InvalidPageNumberException;
use App\Pagination\Paginator;
use App\Repository\RepoRepository;
use App\Repository\StarRepository;
use App\Repository\UserRepository;
use App\Repository\VersionRepository;
use App\Rss\Generator;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use MarcW\RssWriter\Bridge\Symfony\HttpFoundation\RssStreamedResponse;
use MarcW\RssWriter\RssWriter;
use Predis\Client as RedisClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Security;

class DefaultController extends AbstractController
{
    public function __construct(private readonly VersionRepository $repoVersion, private readonly int $diffInterval, private readonly RedisClient $redis, private readonly Security $security)
    {
    }

    #[Route(path: '/', name: 'homepage')]
    public function indexAction(): Response
    {
        if ($this->security->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirect($this->generateUrl('dashboard'));
        }

        return $this->render('default/index.html.twig');
    }

    #[Route(path: '/status', name: 'status')]
    public function statusAction(): Response
    {
        $latest = $this->repoVersion->findLatest();

        if (null === $latest) {
            return $this->json([]);
        }

        $diff = (new \DateTime())->getTimestamp() - $latest['createdAt']->getTimestamp();

        return $this->json([
            'latest' => $latest['createdAt'],
            'diff' => $diff,
            'is_fresh' => $diff / 60 < $this->diffInterval,
        ]);
    }

    #[Route(path: '/dashboard', name: 'dashboard')]
    public function dashboardAction(Request $request, Paginator $paginator): Response
    {
        if (!$this->security->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirect($this->generateUrl('homepage'));
        }

        /** @var User */
        $user = $this->getUser();
        $userId = $user->getId();

        // Pass the item total
        $paginator->setItemTotalCallback(fn () => $this->repoVersion->countForUser($userId));

        // Pass the slice
        $paginator->setSliceCallback(fn ($offset, $length) => $this->repoVersion->findForUser($userId, $offset, $length));

        // Paginate using the current page number
        try {
            $pagination = $paginator->paginate((int) $request->query->get('page', '1'));
        } catch (InvalidPageNumberException $e) {
            throw $this->createNotFoundException($e->getMessage());
        }

        // Avoid displaying empty page when page is too high
        if ($request->query->get('page') > $pagination->getTotalNumberOfPages()) {
            return $this->redirect($this->generateUrl('dashboard'));
        }

        return $this->render('default/dashboard.html.twig', [
            'pagination' => $pagination,
            'sync_status' => $this->redis->get('banditore:user-sync:' . $userId),
        ]);
    }

    /**
     * Empty callback action.
     * The request will be handle by the GithubAuthenticator.
     */
    #[Route(path: '/callback', name: 'github_callback')]
    public function githubCallbackAction(): RedirectResponse
    {
        return $this->redirect($this->generateUrl('github_connect'));
    }

    /**
     * Link to this controller to start the "connect" process.
     */
    #[Route(path: '/connect', name: 'github_connect')]
    public function connectAction(ClientRegistry $oauth): RedirectResponse
    {
        if ($this->security->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirect($this->generateUrl('dashboard'));
        }

        return $oauth
            ->getClient('github')
            ->redirect(['user:email'], []);
    }

    #[Route(path: '/{uuid}.atom', name: 'rss_user')]
    public function rssAction(User $user, Generator $rssGenerator, RssWriter $rssWriter): RssStreamedResponse
    {
        $channel = $rssGenerator->generate(
            $user,
            $this->repoVersion->findForUser($user->getId()),
            $this->generateUrl('rss_user', ['uuid' => $user->getUuid()], UrlGeneratorInterface::ABSOLUTE_URL)
        );

        return new RssStreamedResponse($channel, $rssWriter);
    }

    /**
     * Display some global stats.
     */
    #[Route(path: '/stats', name: 'stats')]
    public function statsAction(RepoRepository $repoRepo, StarRepository $repoStar, UserRepository $repoUser): Response
    {
        $nbRepos = $repoRepo->countTotal();
        $nbReleases = $this->repoVersion->countTotal();
        $nbStars = $repoStar->countTotal();
        $nbUsers = $repoUser->countTotal();

        return $this->render('default/stats.html.twig', [
            'counters' => [
                'nbRepos' => $nbRepos,
                'nbReleases' => $nbReleases,
                'avgReleasePerRepo' => ($nbRepos > 0) ? round($nbReleases / $nbRepos, 2) : 0,
                'avgStarPerUser' => ($nbUsers > 0) ? round($nbStars / $nbUsers, 2) : 0,
            ],
            'mostReleases' => $repoRepo->mostVersionsPerRepo(),
            'lastestReleases' => $this->repoVersion->findLastVersionForEachRepo(20),
        ]);
    }
}
