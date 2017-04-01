<?php

namespace AppBundle\Controller;

use AppBundle\Entity\User;
use AshleyDawson\SimplePagination\Exception\InvalidPageNumberException;
use MarcW\RssWriter\Bridge\Symfony\HttpFoundation\RssStreamedResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
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
     * @Route("/dashboard", name="dashboard")
     */
    public function dashboardAction(Request $request)
    {
        if (!$this->get('security.authorization_checker')->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirect($this->generateUrl('homepage'));
        }

        $repoVersion = $this->get('banditore.repository.version');
        $userId = $this->getUser()->getId();
        $paginator = $this->get('ashley_dawson_simple_pagination.paginator');

        // Pass the item total
        $paginator->setItemTotalCallback(function () use ($repoVersion, $userId) {
            return $repoVersion->countLastVersionForEachRepoForUser($userId);
        });

        // Pass the slice
        $paginator->setSliceCallback(function ($offset, $length) use ($repoVersion, $userId) {
            return $repoVersion->findLastVersionForEachRepoForUser($userId, $offset, $length);
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
     * @ParamConverter("user", class="AppBundle:User")
     */
    public function rssAction(User $user)
    {
        $channel = $this->get('banditore.rss.generator')->generate(
            $user,
            $this->get('banditore.repository.version')->findForUser($user->getId()),
            $this->generateUrl('rss_user', ['uuid' => $user->getUuid()], UrlGeneratorInterface::ABSOLUTE_URL)
        );

        return new RssStreamedResponse($channel, $this->get('banditore.writer.rss'));
    }

    /**
     * Display some global stats.
     *
     * @Route("/stats", name="stats")
     */
    public function statsAction()
    {
        $nbRepos = $this->get('banditore.repository.repo')->count();
        $nbReleases = $this->get('banditore.repository.version')->count();
        $nbStars = $this->get('banditore.repository.star')->count();
        $nbUsers = $this->get('banditore.repository.user')->count();

        return $this->render('default/stats.html.twig', [
            'counters' => [
                'nbRepos' => $nbRepos,
                'nbReleases' => $nbReleases,
                'avgReleasePerRepo' => ($nbRepos > 0) ? round($nbReleases / $nbRepos, 2) : 0,
                'avgStarPerUser' => ($nbUsers > 0) ? round($nbStars / $nbUsers, 2) : 0,
            ],
            'mostReleases' => $this->get('banditore.repository.version')->mostVersionsPerRepo(),
            'lastestReleases' => $this->get('banditore.repository.version')->findLastVersionForEachRepo(),
        ]);
    }
}
