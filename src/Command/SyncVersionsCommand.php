<?php

namespace App\Command;

use App\Message\VersionsSync;
use App\MessageHandler\VersionsSyncHandler;
use App\Repository\RepoRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * This command send contents to opt-in Messenger users.
 * It can send one content or many.
 *
 * Options priority is build this way:
 *     - one content
 *     - many contents
 */
#[AsCommand(name: 'banditore:sync:versions', description: 'Sync new version for each repository')]
class SyncVersionsCommand
{
    public function __construct(private readonly RepoRepository $repoRepository, private readonly VersionsSyncHandler $syncVersions, private readonly TransportInterface $transport, private readonly MessageBusInterface $bus)
    {
    }

    public function __invoke(
        OutputInterface $output,
        #[Option(description: 'Retrieve version only for that repository (using its id)')] string|bool $repoId = false,
        #[Option(description: 'Retrieve version only for that repository (using it full name: username/repo)')] string|bool $repoName = false,
        #[Option(description: 'Push each repo into a queue instead of fetching it right away')] bool $useQueue = false,
    ): int {
        if ($useQueue && $this->transport instanceof MessageCountAwareInterface) {
            // check that queue is empty before pushing new messages
            $count = $this->transport->getMessageCount();
            if (0 < $count) {
                $output->writeln('Current queue as too much messages (<error>' . $count . '</error>), <comment>skipping</comment>.');

                return Command::FAILURE;
            }
        }

        $repos = $this->retrieveRepos($repoId, $repoName);

        if (\count(array_filter($repos)) <= 0) {
            $output->writeln('<error>No repos found</error>');

            return Command::FAILURE;
        }

        $repoChecked = 0;
        $totalRepos = \count($repos);

        foreach ($repos as $repoId) {
            ++$repoChecked;

            $output->writeln('[' . $repoChecked . '/' . $totalRepos . '] Check <info>' . $repoId . '</info> … ');

            $message = new VersionsSync($repoId);

            if ($useQueue) {
                $this->bus->dispatch($message);
            } else {
                $this->syncVersions->__invoke($message);
            }
        }

        $output->writeln('<info>Repo checked: ' . $repoChecked . '</info>');

        return Command::SUCCESS;
    }

    /**
     * Retrieve repos to work on.
     */
    private function retrieveRepos(?string $repoId, ?string $repoName): array
    {
        if ($repoId) {
            return [$repoId];
        }

        if ($repoName) {
            $repo = $this->repoRepository->findOneByFullName((string) $repoName);

            if ($repo) {
                return [$repo->getId()];
            }

            return [];
        }

        return $this->repoRepository->findAllForRelease();
    }
}
