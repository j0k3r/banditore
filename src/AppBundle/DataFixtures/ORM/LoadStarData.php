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
        $star1 = new Star($this->getReference('user1'), $this->getReference('repo1'));

        $manager->persist($star1);
        $manager->flush();

        $this->addReference('star1', $star1);
    }

    /**
     * {@inheritdoc}
     */
    public function getOrder()
    {
        return 30;
    }
}
