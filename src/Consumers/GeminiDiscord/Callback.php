<?php

namespace RPurinton\GeminiDiscord\Consumers\GeminiDiscord;

use Bunny\{
    Channel,
    Message as RabbitMessage
};

use RPurinton\GeminiDiscord\{
    RabbitMQ\Sync,
    MySQL,
    Error,
    Log,
};

class Callback
{
    const DISCORD_QUEUE = 'discord';
    const GEMINI_QUEUE = 'gemini';
    private Log $log;
    private Sync $sync;
    private MySQL $sql;
    private Interaction $interaction;
    private Message $message;

    public function __construct(array $config)
    {
        $this->log = $config['log'];
        $this->log->debug('construct');
        $this->sync = $config['sync'];
        $this->sql = $config['sql'];
    }

    public function init(array $config): void
    {
        $this->log->debug('init');
        $this->interaction = $config['interaction'];
        $this->message = $config['message'];
    }

    public function callback(RabbitMessage $message, Channel $channel): bool
    {
        $this->log->debug('callback', [$message->content]);
        $this->route(json_decode($message->content, true)) or throw new Error('failed to route message');
        $channel->ack($message);
        return true;
    }

    private function route(array $content): bool
    {
        $this->log->debug('route', [$content['t']]);
        if ($content['op'] === 11) return $this->heartbeat($content);
        switch ($content['t']) {
            case 'MESSAGE_CREATE':
            case 'MESSAGE_UPDATE':
                return $this->message->messageCreate($content['d']);
            case 'INTERACTION_HANDLE':
                return $this->interaction->interactionHandle($content['d']);
        }
        return true;
    }

    private function heartbeat(array $content): bool
    {
        $this->log->debug('heartbeat', [$content]);
        $this->sql->query('SELECT 1'); // keep MySQL connection alive
        $this->sync->publish(self::DISCORD_QUEUE, $content) or throw new Error('failed to publish message to discord');
        return true;
    }
}
