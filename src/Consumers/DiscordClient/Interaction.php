<?php

namespace RPurinton\GeminiDiscord\Consumers\DiscordClient;

use Discord\Parts\Interactions\Interaction as DiscordInteraction;
use RPurinton\GeminiDiscord\{
    RabbitMQ\Publisher,
    Log,
    Error,
};

class Interaction
{
    const DISCORD_QUEUE = 'discord';
    const GEMINI_QUEUE = 'gemini';
    private array $interactions = [];
    private Log $log;
    private Publisher $pub;
    private Message $message;

    public function __construct(array $config)
    {
        $this->log = $config['log'];
        $this->pub = $config['pub'];
        $this->log->debug('Interaction::__construct');
    }

    public function init(Message $message): void
    {
        $this->log->debug('Interaction::init');
        $this->message = $message;
    }

    public function interaction(DiscordInteraction $interaction): void
    {
        $this->log->debug('interaction', ['interaction' => $interaction]);
        $this->interactions[$interaction->id] = $interaction;
        $message = [
            'op' => 0,
            't' => 'INTERACTION_HANDLE',
            'd' => json_decode(json_encode($interaction), true)
        ];
        $this->pub->publish(self::GEMINI_QUEUE, $message) or throw new Error('failed to publish message to gemini');
        $interaction->acknowledgeWithResponse(true) or throw new Error('failed to acknowledge interaction');
    }

    public function interactionHandle(array $interactionReply): bool
    {
        $this->log->debug('interactionHandle', ['interaction' => $interactionReply]);
        $this->interactions[$interactionReply['id']]?->updateOriginalResponse($this->message->builder($interactionReply)) or throw new Error('failed to update original response');
        unset($this->interactions[$interactionReply['id']]);
        return true;
    }
}
