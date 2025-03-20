<?php

namespace App\Command;

use App\Message\StarredReposSync;
use App\MessageHandler\StarredReposSyncHandler;
use App\Repository\UserRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * This command sync starred repos from user(s).
 *
 * It can do it:
 *     - right away, might take longer to process
 *     - by publishing a message in a queue
 */
class SyncStarredReposCommand extends Command
{
    private $syncRepo;

    public function __construct(private readonly UserRepository $userRepository, StarredReposSyncHandler $syncRepo, private readonly TransportInterface $transport, private readonly MessageBusInterface $bus)
    {
        $this->syncRepo = $syncRepo;

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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('use_queue') && $this->transport instanceof MessageCountAwareInterface) {
            // check that queue is empty before pushing new messages
            $count = $this->transport->getMessageCount();
            if (0 < $count) {
                $output->writeln('Current queue as too much messages (<error>' . $count . '</error>), <comment>skipping</comment>.');

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

            $message = new StarredReposSync($userId);

            if ($input->getOption('use_queue')) {
                $this->bus->dispatch($message);
            } else {
                $this->syncRepo->__invoke($message);
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
