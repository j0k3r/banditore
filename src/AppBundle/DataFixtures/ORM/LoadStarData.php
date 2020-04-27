<?php

namespace AppBundle\DataFixtures\ORM;

use AppBundle\Entity\Star;
use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;

class LoadStarData extends AbstractFixture implements OrderedFixtureInterface
{
    public function load(ObjectManager $manager)
    {
        /** @var \AppBundle\Entity\User */
        $user1 = $this->getReference('user1');
        /** @var \AppBundle\Entity\Repo */
        $repo1 = $this->getReference('repo1');
        /** @var \AppBundle\Entity\Repo */
        $repo2 = $this->getReference('repo2');

        $star1 = new Star($user1, $repo1);
        $star2 = new Star($user1, $repo2);

        $manager->persist($star1);
        $manager->persist($star2);
        $manager->flush();

        $this->addReference('star1', $star1);
        $this->addReference('star2', $star2);
    }

    /**
     * {@inheritdoc}
     */
    public function getOrder()
    {
        return 30;
    }
}
