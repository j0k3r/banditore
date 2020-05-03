<?php

namespace AppBundle\Controller;

use AppBundle\Entity\User;
use AppBundle\Repository\RepoRepository;
use AppBundle\Repository\StarRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Repository\VersionRepository;
use AppBundle\Rss\Generator;
use AshleyDawson\SimplePagination\Exception\InvalidPageNumberException;
use MarcW\RssWriter\Bridge\Symfony\HttpFoundation\RssStreamedResponse;
use MarcW\RssWriter\RssWriter;
use Predis\Client as RedisClient;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class DefaultController extends Controller
{
    private $repoVersion;
    private $repoRepo;
    private $repoStar;
    private $repoUser;
    private $rssGenerator;
    private $rssWriter;
    private $diffInterval;
    private $redis;

    public function __construct(VersionRepository $repoVersion, RepoRepository $repoRepo, StarRepository $repoStar, UserRepository $repoUser, Generator $rssGenerator, RssWriter $rssWriter, $diffInterval, RedisClient $redis)
    {
        $this->repoVersion = $repoVersion;
        $this->repoRepo = $repoRepo;
        $this->repoStar = $repoStar;
        $this->repoUser = $repoUser;
        $this->rssGenerator = $rssGenerator;
        $this->rssWriter = $rssWriter;
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
    public function dashboardAction(Request $request)
    {
        if (!$this->get('security.authorization_checker')->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirect($this->generateUrl('homepage'));
        }

        /** @var User */
        $user = $this->getUser();
        $userId = $user->getId();
        $paginator = $this->get('ashley_dawson_simple_pagination.paginator_public');

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
    public function connectAction()
    {
        if ($this->get('security.authorization_checker')->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirect($this->generateUrl('dashboard'));
        }

        return $this->get('oauth2.registry')
            ->getClient('github')
            ->redirect([], []);
    }

    /**
     * @Route("/{uuid}.atom", name="rss_user")
     */
    public function rssAction(User $user)
    {
        $channel = $this->rssGenerator->generate(
            $user,
            $this->repoVersion->findForUser($user->getId()),
            $this->generateUrl('rss_user', ['uuid' => $user->getUuid()], UrlGeneratorInterface::ABSOLUTE_URL)
        );

        return new RssStreamedResponse($channel, $this->rssWriter);
    }

    /**
     * Display some global stats.
     *
     * @Route("/stats", name="stats")
     */
    public function statsAction()
    {
        $nbRepos = $this->repoRepo->countTotal();
        $nbReleases = $this->repoVersion->countTotal();
        $nbStars = $this->repoStar->countTotal();
        $nbUsers = $this->repoUser->countTotal();

        return $this->render('default/stats.html.twig', [
            'counters' => [
                'nbRepos' => $nbRepos,
                'nbReleases' => $nbReleases,
                'avgReleasePerRepo' => ($nbRepos > 0) ? round($nbReleases / $nbRepos, 2) : 0,
                'avgStarPerUser' => ($nbUsers > 0) ? round($nbStars / $nbUsers, 2) : 0,
            ],
            'mostReleases' => $this->repoRepo->mostVersionsPerRepo(),
            'lastestReleases' => $this->repoVersion->findLastVersionForEachRepo(20),
        ]);
    }
}
