<?php

namespace AppBundle\Command;

use AppBundle\Entity\Version;
use Cache\Adapter\Memcached\MemcachedCachePool;
use Github\Exception\RuntimeException;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
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
class CheckNewVersionCommand extends ContainerAwareCommand
{
    private $em;
    private $repoRepository;
    private $versionRepository;
    private $client;

    protected function configure()
    {
        $this
            ->setName('banditore:version:check-new')
            ->setDescription('Retrieve new version for repositories')
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

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // define services for a later use
        $this->em = $this->getContainer()->get('doctrine')->getManager();
        $this->repoRepository = $this->getContainer()->get('banditore.repository.repo');
        $this->versionRepository = $this->getContainer()->get('banditore.repository.version');
        // $this->publisher = $this->getContainer()->get('swarrot.publisher');
        $this->client = $this->getContainer()->get('banditore.client.github.application');

        $memcached = new \Memcached();
        $memcached->addServer('localhost', 11211);
        $pool = new MemcachedCachePool($memcached);

        $this->client->addCache($pool);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('repo_id')) {
            $repos = [$input->getOption('repo_id')];
        } elseif ($input->getOption('repo_name')) {
            $repos = [$this->repoRepository->findOneByFullName($input->getOption('repo_name'))];
        } else {
            $repos = $this->repoRepository->findAllForRelease();
        }

        if (count($repos) <= 0) {
            $output->writeln('<comment>No repos found</comment>');

            return 1;
        }

        // push content using the right tool
        if ($input->getOption('use_queue')) {
            // $newVersion = $this->fetchNewVersionsUsingQueue($contents);
        } else {
            $newVersion = $this->fetchNewVersions($output, $repos);
        }

        $output->writeln('<info>New version found: ' . $newVersion . '</info>');

        return 0;
    }

    private function fetchNewVersions(OutputInterface $output, array $repos)
    {
        $globalNewVersion = 0;
        $totalRepos = count($repos);

        foreach ($repos as $i => $repoId) {
            ++$i;
            $newVersion = 0;
            $repo = $this->repoRepository->find($repoId);

            $rateLimit = $this->client->api('rate_limit')->getRateLimits();
            $output->write('[' . $rateLimit['resources']['core']['remaining'] . ' - ' . $i . '/' . $totalRepos . '] Check <info>' . $repo->getFullName() . '</info> ... ');

            list($username, $repoName) = explode('/', $repo->getFullName());

            try {
                $tags = $this->client->api('repo')->tags($username, $repoName);
            } catch (RuntimeException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                continue;
            }

            if (empty($tags)) {
                $output->writeln($newVersion);

                continue;
            }

            foreach ($tags as $tag) {
                $version = $this->versionRepository->findOneBy([
                    'tagName' => $tag['name'],
                    'repo' => $repo->getId(),
                ]);

                if (null !== $version) {
                    continue;
                }

                // is there an associated release?
                $newRelease = [
                    'tag_name' => $tag['name'],
                ];

                try {
                    $newRelease = $this->client->api('repo')->releases()->tag($username, $repoName, $tag['name']);

                    // use same key as tag to store the content of the release
                    $newRelease['message'] = $newRelease['body'];
                } catch (RuntimeException $e) {
                    // catch this
                    //   [Github\Exception\ApiLimitExceedException]
                    //   You have reached GitHub hourly limit! Actual limit is: 5000
                    $commit = $this->client->api('git')->commits()->show($username, $repoName, $tag['commit']['sha']);

                    $newRelease += [
                        'name' => $tag['name'],
                        'draft' => false,
                        'prerelease' => false,
                        'published_at' => $commit['author']['date'],
                        'message' => $commit['message'],
                    ];
                }

                // render markdown in plain html and use default markdown file if it fails
                if (isset($newRelease['message']) && strlen(trim($newRelease['message'])) > 0) {
                    try {
                        $newRelease['message'] = $this->client->api('markdown')->render($newRelease['message'], 'gfm', $repo->getFullName());
                    } catch (RuntimeException $e) {
                        $output->writeln('<error>Failed to parse markdown: ' . $e->getMessage() . '</error>');
                    }
                }

                $version = new Version($repo);
                $version->hydrateFromGithub($newRelease);

                $this->em->persist($version);

                ++$newVersion;
                ++$globalNewVersion;
            }

            $output->writeln($newVersion);

            $this->em->flush();
        }

        return $globalNewVersion;
    }
}
