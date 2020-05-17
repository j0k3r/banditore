<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\RepoRepository;
use App\Repository\StarRepository;
use App\Repository\UserRepository;
use App\Repository\VersionRepository;
use App\Rss\Generator;
use AshleyDawson\SimplePagination\Exception\InvalidPageNumberException;
use AshleyDawson\SimplePagination\Paginator;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use MarcW\RssWriter\Bridge\Symfony\HttpFoundation\RssStreamedResponse;
use MarcW\RssWriter\RssWriter;
use Predis\Client as RedisClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class DefaultController extends AbstractController
{
    private $repoVersion;
    private $diffInterval;
    private $redis;

    public function __construct(VersionRepository $repoVersion, $diffInterval, RedisClient $redis)
    {
        $this->repoVersion = $repoVersion;
        $this->diffInterval = $diffInterval;
        $this->redis = $redis;
    }

    /**
     * @Route("/", name="homepage")
     */
    public function indexAction()
    {
        if ($this->get('security.authorization_checker')->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirect($this->generateUrl('dashboard'));
        }

        return $this->render('default/index.html.twig');
    }

    /**
     * @Route("/status", name="status")
     */
    public function statusAction()
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

    /**
     * @Route("/dashboard", name="dashboard")
     */
    public function dashboardAction(Request $request, Paginator $paginator)
    {
        if (!$this->get('security.authorization_checker')->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirect($this->generateUrl('homepage'));
        }

        /** @var User */
        $user = $this->getUser();
        $userId = $user->getId();

        // Pass the item total
        $paginator->setItemTotalCallback(function () use ($userId) {
            return $this->repoVersion->countForUser($userId);
        });

        // Pass the slice
        $paginator->setSliceCallback(function ($offset, $length) use ($userId) {
            return $this->repoVersion->findForUser($userId, $offset, $length);
        });

        // Paginate using the current page number
        try {
            $pagination = $paginator->paginate((int) $request->query->get('page', 1));
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
     *
     * @Route("/callback", name="github_callback")
     */
    public function githubCallbackAction()
    {
        return $this->redirect($this->generateUrl('github_connect'));
    }

    /**
     * Link to this controller to start the "connect" process.
     *
     * @Route("/connect", name="github_connect")
     */
    public function connectAction(ClientRegistry $oauth)
    {
        if ($this->get('security.authorization_checker')->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirect($this->generateUrl('dashboard'));
        }

        return $oauth
            ->getClient('github')
            ->redirect([], []);
    }

    /**
     * @Route("/{uuid}.atom", name="rss_user")
     */
    public function rssAction(User $user, Generator $rssGenerator, RssWriter $rssWriter)
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
     *
     * @Route("/stats", name="stats")
     */
    public function statsAction(RepoRepository $repoRepo, StarRepository $repoStar, UserRepository $repoUser)
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
