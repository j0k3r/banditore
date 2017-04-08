<?php

namespace AppBundle\EventListener;

use AppBundle\Event\VersionCreatedEvent;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use Swarrot\Broker\Message;
use Swarrot\SwarrotBundle\Broker\Publisher;

class VersionInfoListener
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

    public function queue(VersionCreatedEvent $event)
    {
        $message = new Message(json_encode([
            'version_id' => $event->getVersion()->getId(),
        ]));

        try {
            $this->publisher->publish(
                'banditore.sync_versions_info.publisher',
                $message
            );

            return true;
        } catch (AMQPExceptionInterface $e) {
            return false;
        }
    }
}
