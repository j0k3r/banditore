<?php

namespace AppBundle\Consumer;

use AppBundle\Entity\Repo;
use AppBundle\Entity\Star;
use AppBundle\Entity\User;
use AppBundle\Github\RateLimitTrait;
use AppBundle\Repository\RepoRepository;
use AppBundle\Repository\StarRepository;
use AppBundle\Repository\UserRepository;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Github\Client;
use Psr\Log\LoggerInterface;
use Swarrot\Broker\Message;
use Swarrot\Processor\ProcessorInterface;

/**
 * Consumer message to sync starred repos from user.
 *
 * It might come from:
 *     - when user logged in
 *     - when we periodically sync user starred repos
 */
class SyncStarredRepos implements ProcessorInterface
{
    use RateLimitTrait;

    private $logger;
    private $doctrine;
    private $userRepository;
    private $starRepository;
    private $repoRepository;
    private $client;

    /**
     * Client parameter isn't casted because it can be false when no available client were found by the Github Client Discovery.
     */
    public function __construct(Registry $doctrine, UserRepository $userRepository, StarRepository $starRepository, RepoRepository $repoRepository, $client, LoggerInterface $logger)
    {
        $this->doctrine = $doctrine;
        $this->userRepository = $userRepository;
        $this->starRepository = $starRepository;
        $this->repoRepository = $repoRepository;
        $this->client = $client;
        $this->logger = $logger;
    }

    public function process(Message $message, array $options)
    {
        // in case no client with safe RateLimit were found
        if (false === $this->client) {
            $this->logger->error('No client provided');

            return;
        }

        $data = json_decode($message->getBody(), true);

        $user = $this->userRepository->find($data['user_id']);

        if (null === $user) {
            $this->logger->error('Can not find user', ['user' => $data['user_id']]);

            return;
        }

        $this->logger->notice('Consume banditore.sync_starred_repos message', ['user' => $user->getUsername()]);

        try {
            $nbRepos = $this->doSyncRepo($user);
        } catch (\Exception $e) {
            $this->logger->error('Error while syncing starred repos', ['exception' => $e->getMessage(), 'user' => $user->getUsername()]);

            return;
        }

        $this->logger->notice('Synced repos: ' . $nbRepos, ['user' => $user->getUsername()]);
    }

    /**
     * Do the job to sync repo & star of a user.
     *
     * @param User $user User to work on
     */
    private function doSyncRepo(User $user)
    {
        $newStars = [];
        $page = 1;
        $perPage = 100;
        $starredRepos = $this->client->api('user')->starred($user->getUsername(), $page, $perPage);
        $em = $this->doctrine->getManager();

        do {
            $this->logger->info('    sync ' . count($starredRepos) . ' starred repos', [
                'user' => $user->getUsername(),
                'rate' => $this->getRateLimits($this->client, $this->logger),
            ]);

            foreach ($starredRepos as $starredRepo) {
                $newStars[] = $starredRepo['full_name'];

                $repo = $this->repoRepository->find($starredRepo['id']);

                if (null === $repo) {
                    $repo = new Repo();
                }

                // always update repo information
                $repo->hydrateFromGithub($starredRepo);

                $em->persist($repo);

                $star = $this->starRepository->findOneBy([
                    'repo' => $starredRepo['id'],
                    'user' => $user,
                ]);

                if (null === $star) {
                    $star = new Star($user, $repo);

                    $em->persist($star);
                }

                $em->flush();
            }

            $starredRepos = $this->client->api('user')->starred($user->getUsername(), $page++, $perPage);
        } while (!empty($starredRepos));

        // now remove unstarred repos
        $this->doCleanOldStar($user, $newStars);

        return count($newStars);
    }

    /**
     * Clean old star.
     * When user unstar a repo we also need to remove that association.
     *
     * @param User  $user
     * @param array $newStars Current stars of the user
     *
     * @return mixed
     */
    private function doCleanOldStar(User $user, array $newStars)
    {
        $currentStars = $this->starRepository->findAllByUser($user->getId());

        $starsToRemove = array_diff($currentStars, $newStars);

        if (empty($starsToRemove)) {
            return;
        }

        $starIds = [];
        foreach ($starsToRemove as $starToRemove) {
            $starIds[] = $this->repoRepository->findOneBy(['fullName' => $starToRemove])->getId();
        }

        $this->logger->notice('Removed stars: ' . count($starIds), ['user' => $user->getUsername()]);

        return $this->starRepository->removeFromUser($starIds, $user->getId());
    }
}
