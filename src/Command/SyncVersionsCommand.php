<?php

namespace App\Command;

use App\Message\VersionsSync;
use App\MessageHandler\VersionsSyncHandler;
use App\Repository\RepoRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
class SyncVersionsCommand extends Command
{
    private $syncVersions;

    public function __construct(private readonly RepoRepository $repoRepository, VersionsSyncHandler $syncVersions, private readonly TransportInterface $transport, private readonly MessageBusInterface $bus)
    {
        $this->syncVersions = $syncVersions;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('banditore:sync:versions')
            ->setDescription('Sync new version for each repository')
            ->addOption(
                'repo_id',
                null,
                InputOption::VALUE_REQUIRED,
                'Retrieve version only for that repository (using its id)'
            )
            ->addOption(
                'repo_name',
                null,
                InputOption::VALUE_REQUIRED,
                'Retrieve version only for that repository (using it full name: username/repo)'
            )
            ->addOption(
                'use_queue',
                null,
                InputOption::VALUE_NONE,
                'Push each repo into a queue instead of fetching it right away'
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

                return Command::FAILURE;
            }
        }

        $repos = $this->retrieveRepos($input);

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

            if ($input->getOption('use_queue')) {
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
    private function retrieveRepos(InputInterface $input): array
    {
        if ($input->getOption('repo_id')) {
            return [$input->getOption('repo_id')];
        }

        if ($input->getOption('repo_name')) {
            $repo = $this->repoRepository->findOneByFullName((string) $input->getOption('repo_name'));

            if ($repo) {
                return [$repo->getId()];
            }

            return [];
        }

        return $this->repoRepository->findAllForRelease();
    }
}
