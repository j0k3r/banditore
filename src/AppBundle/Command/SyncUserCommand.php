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
class SyncUserCommand extends ContainerAwareCommand
{
    private $userRepository;
    private $publisher;
    private $syncUser;

    protected function configure()
    {
        $this
            ->setName('banditore:user:sync')
            ->setDescription('Retrieve all users and sync starred repos for them')
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
                'Push each repo into a queue instead of fetching it right away'
            )
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // define services for a later use
        $this->userRepository = $this->getContainer()->get('banditore.repository.user');
        $this->publisher = $this->getContainer()->get('swarrot.publisher');
        $this->syncUser = $this->getContainer()->get('banditore.consumer.sync_user_repo');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $users = [];

        if ($input->getOption('id')) {
            $users = [$input->getOption('id')];
        } elseif ($input->getOption('username')) {
            $user = $this->userRepository->findOneByUsername($input->getOption('username'));

            if ($user) {
                $users = [$user->getId()];
            }
        } else {
            $users = $this->userRepository->findAllToSync();
        }

        if (count(array_filter($users)) <= 0) {
            $output->writeln('<error>No users found</error>');

            return 1;
        }

        $userSynced = 0;
        $totalUsers = count($users);

        foreach ($users as $userId) {
            ++$userSynced;

            $output->writeln('[' . $userSynced . '/' . $totalUsers . '] Sync user <info>' . $userId . '</info> … ');

            $message = new Message(json_encode([
                'user_id' => $userId,
            ]));

            if ($input->getOption('use_queue')) {
                $this->publisher->publish(
                    'banditore.sync_user_repo.publisher',
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
}
