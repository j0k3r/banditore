<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\Version;
use App\Message\StarredReposSync;
use App\Repository\VersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GithubResourceOwner;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class GithubAuthenticator extends OAuth2Authenticator
{
    public function __construct(private readonly ClientRegistry $clientRegistry, private readonly EntityManagerInterface $entityManager, private readonly RouterInterface $router, private readonly MessageBusInterface $bus)
    {
    }

    public function supports(Request $request): ?bool
    {
        // continue ONLY if the current ROUTE matches the check ROUTE
        return 'github_callback' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('github');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client) {
                /** @var GithubResourceOwner */
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

        /** @var VersionRepository */
        $versionRepo = $this->entityManager->getRepository(Version::class);
        $versions = $versionRepo->countForUser($user->getId());

        // if no versions were found, it means the user logged in for the first time
        // and we need to display an explanation message
        $message = 'Successfully logged in!';
        if (0 === $versions) {
            $message = 'Successfully logged in. Your starred repos will soon be synced!';
        }

        /** @var FlashBag */
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
