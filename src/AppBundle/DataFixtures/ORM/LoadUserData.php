<?php

namespace AppBundle\DataFixtures\ORM;

use AppBundle\Entity\User;
use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;

class LoadUserData extends AbstractFixture implements OrderedFixtureInterface
{
    public function load(ObjectManager $manager)
    {
        $user1 = new User();
        $user1->setId(123);
        $user1->setUsername('admin');
        $user1->setName('Bob');
        $user1->setAccessToken('1234567890');
        $user1->setAvatar('http://0.0.0.0/avatar.jpg');

        $manager->persist($user1);
        $manager->flush();

        $this->addReference('user1', $user1);
    }

    /**
     * {@inheritdoc}
     */
    public function getOrder()
    {
        return 10;
    }
}
