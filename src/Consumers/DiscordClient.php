<?php

namespace RPurinton\GeminiDiscord\Consumers;

use Discord\Discord;
use RPurinton\GeminiDiscord\{
    Consumers\DiscordClient\Config,
    Consumers\DiscordClient\Init,
    Consumers\DiscordClient\Interaction as DiscordInteraction,
    Consumers\DiscordClient\Message as DiscordMessage,
    Consumers\DiscordClient\Raw,
    Consumers\DiscordClient\Callback,
    Error,
    Log,
};

class DiscordClient
{
    const DISCORD_QUEUE = 'discord';
    const GEMINI_QUEUE = 'gemini';
    public ?Discord $discord = null;
    private Config $config;
    private Init $init;
    private DiscordInteraction $interaction;
    private DiscordMessage $message;
    private Raw $raw;
    private Callback $callback;
    private Log $log;

    public function __construct(array $config)
    {
        $this->config = $config['config'];
        $this->log = $this->config->log;
        $this->init = $config['init'];
        $this->message = $config['message'];
        $this->interaction = $config['interaction'];
        $this->raw = $config['raw'];
        $this->callback = $config['callback'];
    }

    public function init(): void
    {
        $this->discord = $this->init->init($this->callback->ready(...));
        $this->message->init($this->discord);
        $this->interaction->init($this->message);
        $this->callback->init([
            'discord' => $this->discord,
            'raw' => $this->raw,
            'interaction' => $this->interaction,
            'message' => $this->message,
        ]);
        $this->discord->run();
    }
}
