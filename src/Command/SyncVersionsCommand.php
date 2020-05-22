<?php

namespace App\Command;

use App\Consumer\SyncVersions;
use App\Repository\RepoRepository;
use Swarrot\Broker\Message;
use Swarrot\SwarrotBundle\Broker\AmqpLibFactory;
use Swarrot\SwarrotBundle\Broker\Publisher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
    private $repoRepository;
    private $publisher;
    private $syncVersions;
    private $amqplibFactory;

    public function __construct(RepoRepository $repoRepository, Publisher $publisher, SyncVersions $syncVersions, AmqpLibFactory $amqplibFactory)
    {
        $this->repoRepository = $repoRepository;
        $this->publisher = $publisher;
        $this->syncVersions = $syncVersions;
        $this->amqplibFactory = $amqplibFactory;

        parent::__construct();
    }

    protected function configure()
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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('use_queue')) {
            // check that queue is empty before pushing new messages
            $message = $this->amqplibFactory
                ->getChannel('rabbitmq')
                ->basic_get('banditore.sync_versions');

            if (null !== $message && 0 < $message->delivery_info['message_count']) {
                $output->writeln('Current queue as too much messages (<error>' . $message->delivery_info['message_count'] . '</error>), <comment>skipping</comment>.');

                return 1;
            }
        }

        $repos = $this->retrieveRepos($input);

        if (\count(array_filter($repos)) <= 0) {
            $output->writeln('<error>No repos found</error>');

            return 1;
        }

        $repoChecked = 0;
        $totalRepos = \count($repos);

        foreach ($repos as $repoId) {
            ++$repoChecked;

            $output->writeln('[' . $repoChecked . '/' . $totalRepos . '] Check <info>' . $repoId . '</info> … ');

            $message = new Message((string) json_encode([
                'repo_id' => $repoId,
            ]));

            if ($input->getOption('use_queue')) {
                $this->publisher->publish(
                    'banditore.sync_versions.publisher',
                    $message
                );
            } else {
                $this->syncVersions->process(
                    $message,
                    []
                );
            }
        }

        $output->writeln('<info>Repo checked: ' . $repoChecked . '</info>');

        return 0;
    }

    /**
     * Retrieve repos to work on.
     *
     * @return array
     */
    private function retrieveRepos(InputInterface $input)
    {
        if ($input->getOption('repo_id')) {
            return [$input->getOption('repo_id')];
        }

        if ($input->getOption('repo_name')) {
            $repo = $this->repoRepository->findOneByFullName($input->getOption('repo_name'));

            if ($repo) {
                return [$repo->getId()];
            }

            return [];
        }

        return $this->repoRepository->findAllForRelease();
    }
}
