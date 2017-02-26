<?php

namespace AppBundle\Controller;

use AppBundle\Entity\User;
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
    public function dashboardAction()
    {
        if (!$this->get('security.authorization_checker')->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirect($this->generateUrl('homepage'));
        }

        return $this->render('default/dashboard.html.twig', [
            'repos' => $this->get('banditore.repository.version')->findLastVersionForEachRepoForUser($this->getUser()->getId()),
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
        return $this->get('oauth2.registry')
            ->getClient('github')
            // scopes requested
            ->redirect(['user', 'repo']);
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
}
