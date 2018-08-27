<?php

namespace AppBundle\Command;

use Swarrot\Broker\Message;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
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
class SyncStarredReposCommand extends ContainerAwareCommand
{
    private $userRepository;
    private $publisher;
    private $syncUser;
    private $amqplibFactory;

    protected function configure()
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

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // define services for a later use
        $this->userRepository = $this->getContainer()->get('banditore.repository.user');
        $this->publisher = $this->getContainer()->get('swarrot.publisher');
        $this->syncUser = $this->getContainer()->get('banditore.consumer.sync_starred_repos');
        $this->amqplibFactory = $this->getContainer()->get('swarrot.factory.default');
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

            $message = new Message(json_encode([
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
     *
     * @param InputInterface $input
     *
     * @return array
     */
    private function retrieveUsers(InputInterface $input)
    {
        if ($input->getOption('id')) {
            return [$input->getOption('id')];
        }

        if ($input->getOption('username')) {
            $user = $this->userRepository->findOneByUsername($input->getOption('username'));

            if ($user) {
                return [$user->getId()];
            }

            return [];
        }

        return $this->userRepository->findAllToSync();
    }
}
