<?php

namespace AppBundle\Security;

use AppBundle\Entity\User;
use Doctrine\ORM\EntityManager;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\Provider\GithubClient;
use KnpU\OAuth2ClientBundle\Security\Authenticator\SocialAuthenticator;
use League\OAuth2\Client\Provider\Exception\GithubIdentityProviderException;
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

    public function __construct(ClientRegistry $clientRegistry, EntityManager $em, RouterInterface $router)
    {
        $this->clientRegistry = $clientRegistry;
        $this->em = $em;
        $this->router = $router;
    }

    public function getCredentials(Request $request)
    {
        if ($request->getPathInfo() !== '/callback') {
            // don't auth
            return;
        }

        try {
            return $this->fetchAccessToken($this->getGithubClient());
        } catch (GithubIdentityProviderException $e) {
            return;
        }
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        $githubUser = $this->getGithubClient()->fetchUserFromToken($credentials);

        $existingUser = $this->em->getRepository('AppBundle:User')
            ->find($githubUser->getId());

        if ($existingUser) {
            // always update the access token
            $existingUser->setAccessToken($credentials->getToken());

            $this->em->persist($existingUser);
            $this->em->flush();

            return $existingUser;
        }

        $user = new User();
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
        return new RedirectResponse($this->router->generate('update_repo'));
    }

    public function start(Request $request, AuthenticationException $authException = null)
    {
        return new RedirectResponse($this->router->generate('connect'));
    }

    /**
     * @return GithubClient
     */
    private function getGithubClient()
    {
        return $this->clientRegistry->getClient('github');
    }
}
