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
        $guild_id = $data['guild_id'];
        $guild_name = $data['guild_name'];
        $channel_id = $data['channel_id'];
        $channel_name = $data['channel_name'];
        $channel_topic = $data['channel_topic'];
        $content_string = 'Current Time: ' . date('Y-m-d H:i:s T') . "\n" .
            'Discord Server ID: ' . $guild_id . "\n" .
            'Server Name: ' . $guild_name . "\n" .
            'Channel ID: ' . $channel_id . "\n" .
            'Channel Name: ' . $channel_name . "\n" .
            'Channel Topic: ' . $channel_topic . "\n\n";
        $token_count = $this->prompt->token_count($content_string);
        $content = [];
        $history = $data['history'];
        foreach ($history as $message_id => $message) {
            $history_message = "[Message ID $message_id]\n";
            $history_message .= $message['timestamp'] . ' ';
            $history_message .= $message['author']['username'];
            if (isset($message['member']['nick'])) $history_message .= ' (' . $message['member']['nick'] . ')';
            $message["content"] = str_replace("<@{$this->discord_id}>", 'Gemini', $message["content"]);
            $history_message .= "\n" . $message['content'] . "\n";
            foreach ($message['reactions'] as $emoji => $reaction) $history_message .= $emoji . $reaction['count'] . ' ';
            $history_message .= "\n";
            $message_tokens = $this->prompt->token_count($history_message);
            if ($token_count + $message_tokens > self::HISTORY_LIMIT) continue;
            $token_count += $message_tokens;
            $content[] = $history_message;
        }
        $content_string .= implode("\n", array_reverse($content));
        $content_string .= "Write a single response from Gemini to the channel.";
        $this->prompt->setContent([['role' => 'user', 'parts' => [['text' => $content_string]]]]);
        $response = $this->gemini->getResponse($this->prompt->toJson());
        $text = $response->getText();
        $this->sync->publish('discord', ['op' => 0, 't' => 'MESSAGE_CREATE', 'd' => ['channel_id' => $data['channel_id'], 'content' => $text]]) or throw new Error('failed to publish message to discord');
        return true;
    }
}
