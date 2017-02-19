<?php

namespace AppBundle\Command;

use AppBundle\Entity\Version;
use Cache\Adapter\Redis\RedisCachePool;
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
    private $hubPublisher;
    private $github;

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
        $this->hubPublisher = $this->getContainer()->get('banditore.pubsubhubbub.publisher');

        $this->github = $this->getContainer()->get('banditore.client.github.application');
        $this->github->addCache(new RedisCachePool($this->getContainer()->get('banditore.client.redis')));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $rateLimit = $this->github->api('rate_limit')->getRateLimits();
        if ($rateLimit['resources']['core']['remaining'] === 0) {
            $output->writeln('<error>Github limit reached</error>, reset will apply at: <info>' . date('r', $rateLimit['resources']['core']['reset']) . '</info>');

            return 1;
        }

        if ($input->getOption('repo_id')) {
            $repos = [$input->getOption('repo_id')];
        } elseif ($input->getOption('repo_name')) {
            $repos = [$this->repoRepository->findOneByFullName($input->getOption('repo_name'))];
        } else {
            $repos = $this->repoRepository->findAllForRelease();
        }

        if (count($repos) <= 0) {
            $output->writeln('<error>No repos found</error>');

            return 1;
        }

        $newVersion = $this->fetchNewVersions($output, $repos);

        // notify pubsubhubbub
        $this->hubPublisher->pingHub(array_keys($newVersion));

        $total = 0;
        foreach ($newVersion as $id => $count) {
            $total += $count;
        }

        $output->writeln('<info>New version found: ' . $total . '</info>');

        return 0;
    }

    private function fetchNewVersions(OutputInterface $output, array $repos)
    {
        $globalNewVersion = [];
        $totalRepos = count($repos);

        foreach ($repos as $i => $repoId) {
            ++$i;
            $repo = $this->repoRepository->find($repoId);

            $newVersion = 0;
            $globalNewVersion[$repo->getId()] = 0;

            $rateLimit = $this->github->api('rate_limit')->getRateLimits();
            $output->write('[' . $rateLimit['resources']['core']['remaining'] . ' - ' . $i . '/' . $totalRepos . '] Check <info>' . $repo->getFullName() . '</info> ... ');

            list($username, $repoName) = explode('/', $repo->getFullName());

            try {
                // use git/refs/tags because tags aren't order by date creation
                // using that way we retrieve all tags
                $tags = $this->github->api('git')->tags()->all($username, $repoName);
            } catch (RuntimeException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                continue;
            }

            if (empty($tags)) {
                $output->writeln($newVersion);

                continue;
            }

            foreach ($tags as $tag) {
                // it'll be like `refs/tags/2.2.1`
                $tag['name'] = str_replace('refs/tags/', '', $tag['ref']);
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
                    $newRelease = $this->github->api('repo')->releases()->tag($username, $repoName, $tag['name']);

                    // use same key as tag to store the content of the release
                    $newRelease['message'] = $newRelease['body'];
                } catch (RuntimeException $e) {
                    // when a tag isn't a release, it'll be catched here
                    switch ($tag['object']['type']) {
                        // https://api.github.com/repos/ampproject/amphtml/git/tags/694b8cc3983f52209029605300910507bec700b4
                        case 'tag':
                            $tagInfo = $this->github->api('git')->tags()->show($username, $repoName, $tag['object']['sha']);

                            $newRelease += [
                                'name' => $tag['name'],
                                'prerelease' => false,
                                'published_at' => $tagInfo['tagger']['date'],
                                'message' => $tagInfo['message'],
                            ];
                            break;
                        // https://api.github.com/repos/ampproject/amphtml/git/commits/c0a5834b32ae4b45b4bacf677c391e0f9cca82fb
                        case 'commit':
                            $commitInfo = $this->github->api('git')->commits()->show($username, $repoName, $tag['object']['sha']);

                            $newRelease += [
                                'name' => $tag['name'],
                                'prerelease' => false,
                                'published_at' => $commitInfo['author']['date'],
                                'message' => $commitInfo['message'],
                            ];
                    }
                }

                // render markdown in plain html and use default markdown file if it fails
                if (isset($newRelease['message']) && strlen(trim($newRelease['message'])) > 0) {
                    try {
                        $newRelease['message'] = $this->github->api('markdown')->render($newRelease['message'], 'gfm', $repo->getFullName());
                    } catch (RuntimeException $e) {
                        $output->writeln('<error>Failed to parse markdown: ' . $e->getMessage() . '</error>');
                        continue;
                    }
                }

                $version = new Version($repo);
                $version->hydrateFromGithub($newRelease);

                $this->em->persist($version);

                ++$newVersion;
                ++$globalNewVersion[$repo->getId()];
            }

            $output->writeln($newVersion);

            $this->em->flush();
        }

        return $globalNewVersion;
    }
}
