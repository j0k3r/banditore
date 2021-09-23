<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\Version;
use App\Message\StarredReposSync;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\PassportInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class GithubAuthenticator extends OAuth2Authenticator
{
    private $clientRegistry;
    private $entityManager;
    private $router;
    private $bus;

    public function __construct(ClientRegistry $clientRegistry, EntityManagerInterface $entityManager, RouterInterface $router, MessageBusInterface $bus)
    {
        $this->clientRegistry = $clientRegistry;
        $this->entityManager = $entityManager;
        $this->router = $router;
        $this->bus = $bus;
    }

    public function supports(Request $request): ?bool
    {
        // continue ONLY if the current ROUTE matches the check ROUTE
        return 'github_callback' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): PassportInterface
    {
        $client = $this->clientRegistry->getClient('github');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client) {
                /** @var \League\OAuth2\Client\Provider\GithubResourceOwner */
                $githubUser = $client->fetchUserFromToken($accessToken);

                /** @var User|null */
                $user = $this->entityManager->getRepository(User::class)->find($githubUser->getId());

                // always update user information at login
                if (null === $user) {
                    $user = new User();
                }

                $user->setAccessToken($accessToken->getToken());
                $user->hydrateFromGithub($githubUser);

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        /** @var User */
        $user = $token->getUser();

        /** @var \App\Repository\VersionRepository */
        $versionRepo = $this->entityManager->getRepository(Version::class);
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

        $this->bus->dispatch(new StarredReposSync($user->getId()));

        return new RedirectResponse($this->router->generate('dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());

        return new Response($message, Response::HTTP_FORBIDDEN);
    }
}
