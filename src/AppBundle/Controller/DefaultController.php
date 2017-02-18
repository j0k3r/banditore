<?php

namespace AppBundle\Controller;

use AppBundle\Entity\User;
use AppBundle\Webfeeds\Webfeeds;
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
        if ($request->query->has('sync')) {
            // display message about sync in progress
        }

        return $this->render('default/index.html.twig');
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
            // scopes requested
            ->redirect(['user', 'repo']);
    }

    /**
     * @Route("/{uuid}.atom", name="rss_user")
     * @ParamConverter("user", class="AppBundle:User")
     */
    public function rssAction(Request $request, User $user)
    {
        $releases = $this->get('banditore.repository.version')->findForUser($user->getId());

        $feedUrl = $this->generateUrl('rss_user', ['uuid' => $user->getUuid()], UrlGeneratorInterface::ABSOLUTE_URL);

        $channel = new Channel();
        $channel->addExtension(
            (new AtomLink())
                ->setRel('self')
                ->setHref($feedUrl)
                ->setType('application/rss+xml')
        );
        $channel->addExtension(
            (new AtomLink())
                ->setRel('hub')
                ->setHref('http://pubsubhubbub.appspot.com/')
        );
        $channel->addExtension(
            (new Webfeeds())
                ->setLogo($user->getAvatar())
                ->setIcon($user->getAvatar())
                ->setAccentColor('10556B')
        );
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

        return new RssStreamedResponse($channel, $this->get('banditore.writer.rss'));
    }
}
