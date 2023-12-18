<?php

namespace RPurinton\GeminiDiscord\Consumers\GeminiDiscord;

use RPurinton\GeminiPHP\{GeminiClient, GeminiPrompt};
use RPurinton\GeminiDiscord\{Log, Error, RabbitMQ\Sync};

class Message
{
    const HISTORY_LIMIT = 4096;
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
        // if we have reached this stage then everything has been validated and it's time to send the message to gemini and return the response to discord
        $this->log->debug('messageCreate', ['data' => $data]);
        $history = $data['history'];
        $token_count = 0;
        $content = [];
        foreach ($history as $message) {
            $history_message = [
                'id' => $message['id'],
                'time' => $message['timestamp'],
                'author' => [
                    'id' => $message['author']['id'],
                    'username' => $message['author']['username'],
                    'nick' => $message['member']['nick'] ?? null,
                ],
                'content' => $message['content'],
                'reactions' => [],
            ];
            foreach ($message['reactions'] as $emoji => $reaction) {
                $history_message['reactions'][] = [
                    'e' => $emoji,
                    '#' => $reaction['count'],
                ];
            };
            $message_json = json_encode($history_message);
            $json_tokens = $this->prompt->token_count($message_json);
            if ($token_count + $json_tokens > self::HISTORY_LIMIT) continue;
            $token_count += $json_tokens;
            $content[] = $history_message;
        }
        $content = array_reverse($content);
        $content_string = implode("\n", $content);
        $this->prompt->setContent([['role' => 'user', 'parts' => [['text' => $content_string]]]]);
        // send the prompt to gemini
        $response = $this->gemini->getResponse($this->prompt->toJson());
        // send the response to discord
        $text = $response->getText();
        $this->sync->publish('discord', [
            'op' => 0, 't' => 'MESSAGE_CREATE',
            'd' => ['channel_id' => $data['channel_id'], 'content' => $text]
        ]) or throw new Error('failed to publish message to discord');
        return true;
    }
}
