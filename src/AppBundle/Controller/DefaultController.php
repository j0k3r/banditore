<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Repo;
use AppBundle\Entity\Star;
use AppBundle\Entity\User;
use MarcW\RssWriter\Bridge\Symfony\HttpFoundation\RssStreamedResponse;
use MarcW\RssWriter\Extension\Atom\AtomLink;
use MarcW\RssWriter\Extension\Core\Channel;
use MarcW\RssWriter\Extension\Core\Guid;
use MarcW\RssWriter\Extension\Core\Item;
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
    public function indexAction(Request $request)
    {
        // replace this example code with whatever you need
        return $this->render('default/index.html.twig', [
            'base_dir' => realpath($this->getParameter('kernel.root_dir') . '/..') . DIRECTORY_SEPARATOR,
        ]);
    }

    /**
     * @Route("/update_repo", name="update_repo")
     */
    public function updateRepoAction()
    {
        $em = $this->getDoctrine()->getManager();
        $client = new \Github\Client();
        $client->authenticate($this->getUser()->getAccessToken(), null, \Github\Client::AUTH_HTTP_TOKEN);

        $page = 1;
        $perPage = 50;
        $starredRepos = $client->api('current_user')->starring()->all($page, $perPage);

        do {
            foreach ($starredRepos as $starredRepo) {
                $repo = $this->getDoctrine()->getRepository('AppBundle:Repo')
                    ->find($starredRepo['id']);

                if (null === $repo) {
                    $repo = new Repo();
                    $repo->hydrateFromGithub($starredRepo);

                    $em->persist($repo);
                }

                $star = $this->getDoctrine()->getRepository('AppBundle:Star')
                    ->findOneBy(['repo' => $starredRepo['id'], 'user' => $this->getUser()]);

                if (null === $star) {
                    $star = new Star($this->getUser(), $repo);

                    $em->persist($star);
                }

                $em->flush();
            }

            $starredRepos = $client->api('current_user')->starring()->all($page++, $perPage);
        } while (!empty($starredRepos));

        return $this->redirect($this->generateUrl('homepage'));
    }

    /**
     * Empty callback action.
     * The request will be handle by the GithubAuthenticator.
     *
     * @Route("/callback", name="github_callback")
     */
    public function githubCallbackAction(Request $request)
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
            ->redirect(['user']);
    }

    /**
     * @Route("/{uuid}.atom", name="rss_user")
     * @ParamConverter("user", class="AppBundle:User")
     */
    public function rssAction(Request $request, User $user)
    {
        $releases = $this->get('banditore.repository.version')->findForUser($this->getUser()->getId());

        $feedUrl = $this->generateUrl('rss_user', ['uuid' => $user->getUuid()], UrlGeneratorInterface::ABSOLUTE_URL);

        $channel = new Channel();
        $channel->addExtension((new AtomLink())->setRel('self')->setHref($feedUrl)->setType('application/rss+xml'));
        $channel->addExtension((new AtomLink())->setRel('hub')->setHref('http://pubsubhubbub.appspot.com/'));
        $channel->setTitle('New releases from starred repo of ' . $user->getUserName())
            ->setLink($feedUrl)
            ->setDescription('Here are all the new releases from all repos starred by ' . $user->getUserName())
            ->setLanguage('en')
            ->setCopyright('(c) ' . (new \DateTime())->format('Y') . ' banditore')
            ->setLastBuildDate(isset($releases[0]) ? $releases[0]['createdAt'] : new \DateTime())
            ->setGenerator('banditore');

        foreach ($releases as $release) {
            $item = new Item();
            $item->setTitle($release['fullName'] . ' ' . $release['tagName'])
                ->setLink('https://github.com/' . $release['fullName'] . '/releases/' . $release['tagName'])
                ->setDescription($release['body'])
                ->setPubDate($release['createdAt'])
                ->setGuid((new Guid())->setIsPermaLink(true)->setGuid('https://github.com/' . $release['fullName'] . '/releases/' . $release['tagName']))
            ;
            $channel->addItem($item);
        }

        return new RssStreamedResponse($channel, $this->get('banditore.writer.atom'));
    }
}
