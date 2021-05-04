<?php

namespace App\Rss;

use App\Entity\User;
use App\Webfeeds\Webfeeds;
use MarcW\RssWriter\Extension\Atom\AtomLink;
use MarcW\RssWriter\Extension\Core\Channel;
use MarcW\RssWriter\Extension\Core\Guid;
use MarcW\RssWriter\Extension\Core\Item;

/**
 * Generate the RSS for a user.
 */
class Generator
{
    public const CHANNEL_TITLE = 'New releases from starred repo of %USERNAME%';
    public const CHANNEL_DESCRIPTION = 'Here are all the new releases from all repos starred by %USERNAME%';

    /**
     * It will return the RSS for the given user with all the latests releases given.
     *
     * @param User   $user     User which require the RSS
     * @param array  $releases An array of releases information
     * @param string $feedUrl  The feed URL
     *
     * @return Channel Information to be dumped by `RssStreamedResponse` for example
     */
    public function generate(User $user, array $releases, $feedUrl)
    {
        $channel = new Channel();
        $channel->addExtension(
            (new AtomLink())
                ->setRel('self')
                ->setHref($feedUrl)
                ->setType('application/rss+xml')
        );
        $channel->addExtension(
            (new AtomLink())
                ->setRel('hub')
                ->setHref('http://pubsubhubbub.appspot.com/')
        );
        $channel->addExtension(
            (new Webfeeds())
                ->setLogo($user->getAvatar())
                ->setIcon($user->getAvatar())
                ->setAccentColor('10556B')
        );
        $channel->setTitle(str_replace('%USERNAME%', $user->getUserName(), self::CHANNEL_TITLE))
            ->setLink($feedUrl)
            ->setDescription(str_replace('%USERNAME%', $user->getUserName(), self::CHANNEL_DESCRIPTION))
            ->setLanguage('en')
            ->setCopyright('(c) ' . (new \DateTime())->format('Y') . ' banditore')
            ->setLastBuildDate(isset($releases[0]) ? $releases[0]['createdAt'] : new \DateTime())
            ->setGenerator('banditore');

        foreach ($releases as $release) {
            // build repo top information
            $repoHome = $release['homepage'] ? '(<a href="' . $release['homepage'] . '">' . $release['homepage'] . '</a>)' : '';
            $repoLanguage = $release['language'] ? '<p>#' . $release['language'] . '</p>' : '';
            $repoInformation = '<table>
               <tr>
                  <td>
                     <a href="https://github.com/' . $release['fullName'] . '">
                        <img src="' . $release['ownerAvatar'] . '&amp;s=140" alt="' . $release['fullName'] . '" title="' . $release['fullName'] . '" />
                     </a>
                  </td>
                  <td>
                     <b><a href="https://github.com/' . $release['fullName'] . '">' . $release['fullName'] . '</a></b>
                     ' . $repoHome . '<br/>
                     ' . $release['description'] . '<br/>
                     ' . $repoLanguage . '
                  </td>
               </tr>
            </table>
            <hr/>';

            $item = new Item();
            $item->setTitle($release['fullName'] . ' ' . $release['tagName'])
                ->setLink('https://github.com/' . $release['fullName'] . '/releases/' . urlencode($release['tagName']))
                ->setDescription($repoInformation . $release['body'])
                ->setPubDate($release['createdAt'])
                ->setGuid((new Guid())->setIsPermaLink(true)->setGuid('https://github.com/' . $release['fullName'] . '/releases/' . urlencode($release['tagName'])))
            ;
            $channel->addItem($item);
        }

        return $channel;
    }
}
