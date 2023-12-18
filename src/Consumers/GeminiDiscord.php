<?php

namespace RPurinton\GeminiDiscord\Consumers;

use Discord\Discord;
use RPurinton\GeminiDiscord\{
    Consumers\GeminiDiscord\GeminiConfig,
    Consumers\GeminiDiscord\Init,
    Consumers\GeminiDiscord\Interaction,
    Consumers\GeminiDiscord\Message,
    Consumers\GeminiDiscord\Callback,
    Error,
    Log,
};

class GeminiDiscord
{
    const DISCORD_QUEUE = 'discord';
    const GEMINI_QUEUE = 'gemini';
    private GeminiConfig $config;
    private Init $init;
    private Interaction $interaction;
    private Message $message;
    private Callback $callback;
    private Log $log;

    public function __construct(array $config)
    {
        $this->config = $config['config'];
        $this->log = $this->config->log;
        $this->init = $config['init'];
        $this->message = $config['message'];
        $this->interaction = $config['interaction'];
        $this->callback = $config['callback'];
    }

    public function init(): bool
    {
        $discord_id = $this->init->init($this->callback->callback(...));
        $this->message->init($discord_id);
        $this->callback->init([
            'interaction' => $this->interaction,
            'message' => $this->message,
        ]);
        return true;
    }
}
