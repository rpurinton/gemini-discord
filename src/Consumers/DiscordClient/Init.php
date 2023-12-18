<?php

namespace RPurinton\GeminiDiscord\Consumers\DiscordClient;

use Discord\{Discord, WebSockets\Intents};
use RPurinton\GeminiDiscord\{
    Consumers\DiscordClient\Config,
    Error,
};

class Init
{
    private ?array $discord_config = null;
    private ?Discord $discord = null;

    public function __construct(private Config $config)
    {
        $config->token = $this->getToken();
        $this->discord_config = [
            'token' => $config->token,
            'logger' => $config->log,
            'loop' => $config->loop,
            'intents' => Intents::getDefaultIntents(),
            'loadAllMembers' => true,
        ];
    }

    public function init(callable $callable): Discord
    {
        $this->discord = new Discord($this->discord_config);
        $this->discord->on('ready', $callable);
        return $this->discord;
    }

    private function getToken(): string
    {
        $result = $this->config->sql->query('SELECT `discord_token` FROM `discord_tokens` LIMIT 1');
        if ($result === false) throw new Error('failed to get discord_token');
        if ($result->num_rows === 0) throw new Error('no discord_token found');
        $row = $result->fetch_assoc();
        $token = $row['discord_token'];
        $this->validateToken($token);
        return $token;
    }

    private function validateToken($token)
    {
        if (!is_string($token)) throw new Error('token is not a string');
        if (strlen($token) === 0) throw new Error('token is empty');
        if (strlen($token) !== 72) throw new Error('token is not 72 characters');
        return true;
    }
}
