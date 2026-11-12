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
        $channel->setTitle(str_replace('%USERNAME%', $user->getUsername(), self::CHANNEL_TITLE))
            ->setLink($feedUrl)
            ->setDescription(str_replace('%USERNAME%', $user->getUsername(), self::CHANNEL_DESCRIPTION))
            ->setLanguage('en')
            ->setCopyright('(c) ' . (new \DateTime())->format('Y') . ' banditore')
            ->setLastBuildDate(isset($releases[0]) ? $releases[0]['createdAt'] : new \DateTime())
            ->setGenerator('banditore');

        foreach ($releases as $release) {
            // build repo top information
            // Escape attacker-controlled GitHub repo fields before embedding them as HTML in the
            // feed (the item description is emitted verbatim inside CDATA). $release['body'] is left
            // as-is because it is already sanitized via GitHub's gfm markdown rendering.
            $homepage = htmlspecialchars((string) $release['homepage'], ENT_QUOTES);
            $language = htmlspecialchars((string) $release['language'], ENT_QUOTES);
            $fullName = htmlspecialchars((string) $release['fullName'], ENT_QUOTES);
            $ownerAvatar = htmlspecialchars((string) $release['ownerAvatar'], ENT_QUOTES);
            $description = htmlspecialchars((string) $release['description'], ENT_QUOTES);

            $repoHome = $release['homepage'] ? '(<a href="' . $homepage . '">' . $homepage . '</a>)' : '';
            $repoLanguage = $release['language'] ? '<p>#' . $language . '</p>' : '';
            $repoInformation = '<table>
               <tr>
                  <td>
                     <a href="https://github.com/' . $fullName . '">
                        <img src="' . $ownerAvatar . '&amp;s=140" alt="' . $fullName . '" title="' . $fullName . '" />
                     </a>
                  </td>
                  <td>
                     <b><a href="https://github.com/' . $fullName . '">' . $fullName . '</a></b>
                     ' . $repoHome . '<br/>
                     ' . $description . '<br/>
                     ' . $repoLanguage . '
                  </td>
               </tr>
            </table>
            <hr/>';

            $item = new Item();
            $item->setTitle($release['fullName'] . ' ' . $release['tagName'])
                ->setLink('https://github.com/' . $release['fullName'] . '/releases/' . urlencode((string) $release['tagName']))
                ->setDescription($repoInformation . $release['body'])
                ->setPubDate($release['createdAt'])
                ->setGuid((new Guid())->setIsPermaLink(true)->setGuid('https://github.com/' . $release['fullName'] . '/releases/' . urlencode((string) $release['tagName'])))
            ;
            $channel->addItem($item);
        }

        return $channel;
    }
}
