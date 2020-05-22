<?php

namespace App\Tests\Cache;

use App\Cache\CustomRedisCachePool;
use Cache\Adapter\Common\CacheItem;
use GuzzleHttp\Psr7\Response;
use Predis\Response\Status;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CustomRedisCachePoolTest extends WebTestCase
{
    public function testResponseWithEmptyBody()
    {
        $redisStatus = new Status('OK');
        $cache = $this->getMockBuilder('Predis\ClientInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $cache->expects($this->once())
            ->method('__call')
            ->willReturn($redisStatus);

        $body = (string) json_encode([]);

        $response = new Response(
            200,
            [],
            $body
        );
        $item = new CacheItem('superkey', true, [
            'response' => $response,
            'body' => $body,
        ]);

        $cachePool = new CustomRedisCachePool($cache);
        $cachePool->save($item);
    }

    public function testResponseWith404()
    {
        $redisStatus = new Status('OK');
        $cache = $this->getMockBuilder('Predis\ClientInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $cache->expects($this->once())
            ->method('__call')
            ->willReturn($redisStatus);

        $response = new Response(404);
        $item = new CacheItem('superkey', true, [
            'response' => $response,
            'body' => '',
        ]);

        $cachePool = new CustomRedisCachePool($cache);
        $cachePool->save($item);
    }

    public function testResponseWithRelease()
    {
        $cache = $this->getMockBuilder('Predis\ClientInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $cache->expects($this->never())
            ->method('__call');

        $body = (string) json_encode([
            'tag_name' => 'V1.1.0',
            'name' => 'V1.1.0',
            'prerelease' => false,
            'published_at' => '2014-12-01T18:28:39Z',
            'body' => 'This is the first release after our major push.',
        ]);

        $response = new Response(
            200,
            [],
            $body
        );
        $item = new CacheItem('superkey', true, [
            'response' => $response,
            'body' => $body,
        ]);

        $cachePool = new CustomRedisCachePool($cache);
        $cachePool->save($item);
    }

    public function testResponseWithRefTags()
    {
        $redisStatus = new Status('OK');
        $cache = $this->getMockBuilder('Predis\ClientInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $cache->expects($this->once())
            ->method('__call')
            ->willReturn($redisStatus);

        $body = (string) json_encode([[
                'ref' => 'refs/tags/1.0.0',
                'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/refs/tags/1.0.0',
                'object' => [
                    'sha' => '04b99722e0c25bfc45926cd3a1081c04a8e950ed',
                    'type' => 'commit',
                    'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/commits/04b99722e0c25bfc45926cd3a1081c04a8e950ed',
                ],
            ],
            [
                'ref' => 'refs/tags/1.0.1',
                'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/refs/tags/1.0.1',
                'object' => [
                    'sha' => '4845571072d49c2794b165482420b66c206a942a',
                    'type' => 'commit',
                    'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/commits/4845571072d49c2794b165482420b66c206a942a',
                ],
            ],
        ]);

        $response = new Response(
            200,
            [],
            $body
        );
        $item = new CacheItem('superkey', true, [
            'response' => $response,
            'body' => $body,
        ]);

        $cachePool = new CustomRedisCachePool($cache);
        $cachePool->save($item);
    }

    public function testResponseWithTag()
    {
        $redisStatus = new Status('OK');
        $cache = $this->getMockBuilder('Predis\ClientInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $cache->expects($this->once())
            ->method('__call')
            ->willReturn($redisStatus);

        $body = (string) json_encode([[
            'name' => '2.0.1',
            'zipball_url' => 'https://api.github.com/repos/snc/SncRedisBundle/zipball/2.0.1',
            'tarball_url' => 'https://api.github.com/repos/snc/SncRedisBundle/tarball/2.0.1',
            'commit' => [
                'sha' => '02c808d157c79ac32777e19f3ec31af24a32d2df',
                'url' => 'https://api.github.com/repos/snc/SncRedisBundle/commits/02c808d157c79ac32777e19f3ec31af24a32d2df',
            ],
        ]]);

        $response = new Response(
            200,
            [],
            $body
        );
        $item = new CacheItem('superkey', true, [
            'response' => $response,
            'body' => $body,
        ]);

        $cachePool = new CustomRedisCachePool($cache);
        $cachePool->save($item);
    }

    public function testResponseWithStarredRepos()
    {
        $redisStatus = new Status('OK');
        $cache = $this->getMockBuilder('Predis\ClientInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $cache->expects($this->once())
            ->method('__call')
            ->willReturn($redisStatus);

        $body = (string) json_encode([[
            'description' => 'banditore',
            'homepage' => 'http://banditore.io',
            'language' => 'PHP',
            'name' => 'banditore',
            'full_name' => 'j0k3r/banditore',
            'id' => 666,
            'owner' => [
                'avatar_url' => 'http://avatar.api/banditore.jpg',
            ],
        ]]);

        $response = new Response(
            200,
            [],
            $body
        );
        $item = new CacheItem('superkey', true, [
            'response' => $response,
            'body' => $body,
        ]);

        $cachePool = new CustomRedisCachePool($cache);
        $cachePool->save($item);
    }
}
