<?php

namespace AppBundle\EventListener;

use AppBundle\Event\MaxRssItemsReachedEvent;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use Swarrot\Broker\Message;
use Swarrot\SwarrotBundle\Broker\Publisher;

class MoreVersionListener
{
    /** @var Publisher */
    protected $publisher;

    /**
     * Create a new subscriber.
     *
     * @param Publisher $publisher Used to push a message to RabbitMQ
     */
    public function __construct(Publisher $publisher)
    {
        $this->publisher = $publisher;
    }

    public function queue(MaxRssItemsReachedEvent $event)
    {
        $message = new Message(json_encode([
            'repo_id' => $event->getRepo()->getId(),
        ]));

        try {
            $this->publisher->publish(
                'banditore.sync_versions.publisher',
                $message
            );

            return true;
        } catch (AMQPExceptionInterface $e) {
            return false;
        }
    }
}
