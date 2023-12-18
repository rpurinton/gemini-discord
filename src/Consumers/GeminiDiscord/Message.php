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
        // reverse the message history
        $history = array_reverse($data['history']);
        $token_count = 0;
        $content = [];
        foreach ($history as $message) {
            $history_message['id'] = $message['id'];
            $history_message['time'] = $message['timestamp'];
            $history_message['author'] = [
                'id' => $message['author']['id'],
                'username' => $message['author']['username'],
                'nick' => $message['member']['nick'] ?? null,
            ];
            $history_message['content'] = $message['content'];
            foreach ($message['reactions'] as $reaction) {
                $history_message['reactions'][] = [
                    'e' => $reaction['emoji']['name'],
                    '#' => $reaction['count'],
                ];
            };
            // TODO: count the tokens
            // TODO: break when max tokens reached
        }
        $content = array_reverse($content);
        // TODO: add the history to the prompt contents

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
