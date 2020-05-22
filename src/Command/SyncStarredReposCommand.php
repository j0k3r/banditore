<?php

namespace App\Command;

use App\Consumer\SyncStarredRepos;
use App\Repository\UserRepository;
use Swarrot\Broker\Message;
use Swarrot\SwarrotBundle\Broker\AmqpLibFactory;
use Swarrot\SwarrotBundle\Broker\Publisher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command sync starred repos from user(s).
 *
 * It can do it:
 *     - right away, might take longer to process
 *     - by publishing a message in a queue
 */
class SyncStarredReposCommand extends Command
{
    private $userRepository;
    private $publisher;
    private $syncUser;
    private $amqplibFactory;

    public function __construct(UserRepository $userRepository, Publisher $publisher, SyncStarredRepos $syncUser, AmqpLibFactory $amqplibFactory)
    {
        $this->userRepository = $userRepository;
        $this->publisher = $publisher;
        $this->syncUser = $syncUser;
        $this->amqplibFactory = $amqplibFactory;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('banditore:sync:starred-repos')
            ->setDescription('Sync starred repos for all users')
            ->addOption(
                'id',
                null,
                InputOption::VALUE_REQUIRED,
                'Retrieve only one user using its id'
            )
            ->addOption(
                'username',
                null,
                InputOption::VALUE_REQUIRED,
                'Retrieve only one user using its username'
            )
            ->addOption(
                'use_queue',
                null,
                InputOption::VALUE_NONE,
                'Push each user into a queue instead of fetching it right away'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('use_queue')) {
            // check that queue is empty before pushing new messages
            $message = $this->amqplibFactory
                ->getChannel('rabbitmq')
                ->basic_get('banditore.sync_starred_repos');

            if (null !== $message && 0 < $message->delivery_info['message_count']) {
                $output->writeln('Current queue as too much messages (<error>' . $message->delivery_info['message_count'] . '</error>), <comment>skipping</comment>.');

                return 1;
            }
        }

        $users = $this->retrieveUsers($input);

        if (\count(array_filter($users)) <= 0) {
            $output->writeln('<error>No users found</error>');

            return 1;
        }

        $userSynced = 0;
        $totalUsers = \count($users);

        foreach ($users as $userId) {
            ++$userSynced;

            $output->writeln('[' . $userSynced . '/' . $totalUsers . '] Sync user <info>' . $userId . '</info> … ');

            $message = new Message((string) json_encode([
                'user_id' => $userId,
            ]));

            if ($input->getOption('use_queue')) {
                $this->publisher->publish(
                    'banditore.sync_starred_repos.publisher',
                    $message
                );
            } else {
                $this->syncUser->process(
                    $message,
                    []
                );
            }
        }

        $output->writeln('<info>User synced: ' . $userSynced . '</info>');

        return 0;
    }

    /**
     * Retrieve users to work on.
     */
    private function retrieveUsers(InputInterface $input): array
    {
        if ($input->getOption('id')) {
            return [$input->getOption('id')];
        }

        if ($input->getOption('username')) {
            $user = $this->userRepository->findOneByUsername((string) $input->getOption('username'));

            if ($user) {
                return [$user->getId()];
            }

            return [];
        }

        return $this->userRepository->findAllToSync();
    }
}
