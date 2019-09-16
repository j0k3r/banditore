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
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class DefaultController extends Controller
{
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
    public function statusAction(VersionRepository $repoVersion)
    {
        $latest = $repoVersion->findLatest();

        if (null === $latest) {
            return $this->json([]);
        }

        $diff = (new \DateTime())->getTimestamp() - $latest['createdAt']->getTimestamp();

        return $this->json([
            'latest' => $latest['createdAt'],
            'diff' => $diff,
            // assume latest version is at most 2h old
            'is_fresh' => $diff / 60 < 120,
        ]);
    }

    /**
     * @Route("/dashboard", name="dashboard")
     */
    public function dashboardAction(Request $request, VersionRepository $repoVersion)
    {
        if (!$this->get('security.authorization_checker')->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirect($this->generateUrl('homepage'));
        }

        $userId = $this->getUser()->getId();
        $paginator = $this->get('ashley_dawson_simple_pagination.paginator_public');

        // Pass the item total
        $paginator->setItemTotalCallback(function () use ($repoVersion, $userId) {
            return $repoVersion->countForUser($userId);
        });

        // Pass the slice
        $paginator->setSliceCallback(function ($offset, $length) use ($repoVersion, $userId) {
            return $repoVersion->findForUser($userId, $offset, $length);
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
            ->redirect();
    }

    /**
     * @Route("/{uuid}.atom", name="rss_user")
     */
    public function rssAction(User $user, Generator $rssGenerator, VersionRepository $repoVersion, RssWriter $rssWriter)
    {
        $channel = $rssGenerator->generate(
            $user,
            $repoVersion->findForUser($user->getId()),
            $this->generateUrl('rss_user', ['uuid' => $user->getUuid()], UrlGeneratorInterface::ABSOLUTE_URL)
        );

        return new RssStreamedResponse($channel, $rssWriter);
    }

    /**
     * Display some global stats.
     *
     * @Route("/stats", name="stats")
     */
    public function statsAction(RepoRepository $repoRepo, VersionRepository $repoVersion, StarRepository $repoStar, UserRepository $repoUser)
    {
        $nbRepos = $repoRepo->countTotal();
        $nbReleases = $repoVersion->countTotal();
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
            'lastestReleases' => $repoVersion->findLastVersionForEachRepo(20),
        ]);
    }
}
