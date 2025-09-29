<?php

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Knp\Bundle\TimeBundle\KnpTimeBundle;
use KnpU\OAuth2ClientBundle\KnpUOAuth2ClientBundle;
use Sentry\SentryBundle\SentryBundle;
use Snc\RedisBundle\SncRedisBundle;
use Symfony\Bundle\DebugBundle\DebugBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

return [
    FrameworkBundle::class => ['all' => true],
    DoctrineBundle::class => ['all' => true],
    DoctrineMigrationsBundle::class => ['all' => true],
    KnpTimeBundle::class => ['all' => true],
    KnpUOAuth2ClientBundle::class => ['all' => true],
    SentryBundle::class => ['prod' => true],
    SncRedisBundle::class => ['all' => true],
    MonologBundle::class => ['all' => true],
    DoctrineFixturesBundle::class => ['dev' => true, 'test' => true],
    TwigBundle::class => ['all' => true],
    TwigExtraBundle::class => ['all' => true],
    SecurityBundle::class => ['all' => true],
    DebugBundle::class => ['dev' => true, 'test' => true],
    WebProfilerBundle::class => ['dev' => true, 'test' => true],
];
