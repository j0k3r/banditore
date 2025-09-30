<?php

namespace App\Command;

use App\Message\StarredReposSync;
use App\MessageHandler\StarredReposSyncHandler;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
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
#[AsCommand(name: 'banditore:sync:starred-repos', description: 'Sync starred repos for all users')]
class SyncStarredReposCommand
{
    public function __construct(private readonly UserRepository $userRepository, private readonly StarredReposSyncHandler $syncRepo, private readonly TransportInterface $transport, private readonly MessageBusInterface $bus)
    {
    }

    public function __invoke(
        OutputInterface $output,
        #[Option(description: 'Retrieve only one user using its id')] string|bool $id = false,
        #[Option(description: 'Retrieve only one user using its username')] string|bool $username = false,
        #[Option(description: 'Push each user into a queue instead of fetching it right away')] bool $useQueue = false,
    ): int {
        if ($useQueue && $this->transport instanceof MessageCountAwareInterface) {
            // check that queue is empty before pushing new messages
            $count = $this->transport->getMessageCount();
            if (0 < $count) {
                $output->writeln('Current queue as too much messages (<error>' . $count . '</error>), <comment>skipping</comment>.');

                return Command::FAILURE;
            }
        }

        $users = $this->retrieveUsers($id, $username);

        if (\count(array_filter($users)) <= 0) {
            $output->writeln('<error>No users found</error>');

            return Command::FAILURE;
        }

        $userSynced = 0;
        $totalUsers = \count($users);

        foreach ($users as $userId) {
            ++$userSynced;

            $output->writeln('[' . $userSynced . '/' . $totalUsers . '] Sync user <info>' . $userId . '</info> … ');

            $message = new StarredReposSync($userId);

            if ($useQueue) {
                $this->bus->dispatch($message);
            } else {
                $this->syncRepo->__invoke($message);
            }
        }

        $output->writeln('<info>User synced: ' . $userSynced . '</info>');

        return Command::SUCCESS;
    }

    /**
     * Retrieve users to work on.
     */
    private function retrieveUsers(?string $id, ?string $username): array
    {
        if ($id) {
            return [$id];
        }

        if ($username) {
            $user = $this->userRepository->findOneByUsername((string) $username);

            if ($user) {
                return [$user->getId()];
            }

            return [];
        }

        return $this->userRepository->findAllToSync();
    }
}
