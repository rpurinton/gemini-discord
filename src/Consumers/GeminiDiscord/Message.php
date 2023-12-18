<?php

namespace RPurinton\GeminiDiscord\Consumers\GeminiDiscord;

use RPurinton\GeminiPHP\{GeminiClient, GeminiPrompt};
use RPurinton\GeminiDiscord\{Log, Error, RabbitMQ\Sync};

class Message
{
    private ?Log $log = null;
    private ?Sync $sync = null;
    private ?GeminiClient $gemini = null;
    private ?GeminiPrompt $prompt = null;
    private ?int $discord_id = null;

    public function __construct(array $config)
    {
        $this->log = $config['log'];
        $this->log->debug('Message::__construct');
        $this->sync = $config['sync'];
        $this->gemini = $config['gemini'];
        $this->prompt = $config['prompt'];
    }

    public function init(int $discord_id): void
    {
        $this->log->debug('Message::init', ['discord_id' => $discord_id]);
        $this->discord_id = $discord_id;
    }

    public function messageCreate(array $data): bool
    {
        $this->log->debug('messageCreate', ['data' => $data]);
        $content = $data['content'];
        $content = str_replace('<@' . $this->discord_id . '>', '', $content);
        $base_content = $this->prompt['contents'];
        $this->prompt->push(['role' => 'user', 'parts' => ['text' => $content]]);
        $response = $this->gemini->getResponse($this->prompt->toJson());
        $text = $response->getText();
        $this->log->debug('messageCreate', ['text' => $text]);
        if (empty($text)) return true;
        $this->prompt->push(['role' => 'assistant', 'parts' => ['text' => $text]]);
        $this->sync->publish('discord', [
            'op' => 0, // DISPATCH
            't' => 'MESSAGE_CREATE',
            'd' => [
                'channel_id' => $data['channel_id'],
                'content' => $text,
            ]
        ]) or throw new Error('failed to publish message to discord');
        return true;
    }
}
