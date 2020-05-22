<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\Version;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\SocialAuthenticator;
use Swarrot\Broker\Message;
use Swarrot\SwarrotBundle\Broker\Publisher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class GithubAuthenticator extends SocialAuthenticator
{
    private $clientRegistry;
    private $em;
    private $router;
    private $publisher;

    public function __construct(ClientRegistry $clientRegistry, EntityManagerInterface $em, RouterInterface $router, Publisher $publisher)
    {
        $this->clientRegistry = $clientRegistry;
        $this->em = $em;
        $this->router = $router;
        $this->publisher = $publisher;
    }

    public function supports(Request $request)
    {
        return 'github_callback' === $request->attributes->get('_route');
    }

    public function getCredentials(Request $request)
    {
        return $this->fetchAccessToken($this->getGithubClient());
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        /** @var \League\OAuth2\Client\Provider\GithubResourceOwner */
        $githubUser = $this->getGithubClient()->fetchUserFromToken($credentials);

        /** @var User|null */
        $user = $this->em->getRepository(User::class)->find($githubUser->getId());

        // always update user information at login
        if (null === $user) {
            $user = new User();
        }

        $user->setAccessToken($credentials->getToken());
        $user->hydrateFromGithub($githubUser);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        // failure, what failure?
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        /** @var User */
        $user = $token->getUser();

        /** @var \App\Repository\VersionRepository */
        $versionRepo = $this->em->getRepository(Version::class);
        $versions = $versionRepo->countForUser($user->getId());

        // if no versions were found, it means the user logged in for the first time
        // and we need to display an explanation message
        $message = 'Successfully logged in!';
        if (0 === $versions) {
            $message = 'Successfully logged in. Your starred repos will soon be synced!';
        }

        /** @var \Symfony\Component\HttpFoundation\Session\Flash\FlashBag */
        $flash = $request->getSession()->getBag('flashes');
        $flash->add('info', $message);

        $this->publisher->publish(
            'banditore.sync_starred_repos.publisher',
            new Message((string) json_encode([
                'user_id' => $user->getId(),
            ]))
        );

        return new RedirectResponse($this->router->generate('dashboard'));
    }

    public function start(Request $request, AuthenticationException $authException = null)
    {
        return new RedirectResponse($this->router->generate('connect'));
    }

    /**
     * @return \KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface
     */
    private function getGithubClient()
    {
        return $this->clientRegistry->getClient('github');
    }
}
