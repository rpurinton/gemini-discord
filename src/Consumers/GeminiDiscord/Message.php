<?php

namespace RPurinton\GeminiDiscord\Consumers\GeminiDiscord;

use RPurinton\GeminiPHP\{GeminiClient, GeminiPrompt};
use RPurinton\GeminiDiscord\{Log, Error, RabbitMQ\Sync};

class Message
{
    const HISTORY_LIMIT = 4096;
    const DISCORD_QUEUE = 'discord';
    private ?Log $log = null;
    private ?Sync $sync = null;
    private ?GeminiClient $gemini = null;
    private ?GeminiPrompt $prompt = null;
    private ?int $discord_id = null;

    public function __construct(array $config)
    {
        $this->log = $config['log'];
        $this->sync = $config['sync'];
        $this->gemini = $config['gemini'];
        $this->prompt = $config['prompt'];
    }

    public function init(int $discord_id): void
    {
        $this->discord_id = $discord_id;
    }

    public function messageCreate(array $data): bool
    {
        $content_string = $this->createContentString($data);
        $content_string .= $this->createHistoryContent($data, $content_string);
        $content_string .= $this->createSystemMessage();
        $this->prompt->setContent([['role' => 'user', 'parts' => [['text' => $content_string]]]]);
        $this->log->debug('messageCreate', ['prompt' => $this->prompt->toJson()]);
        try {
            $response = $this->gemini->getResponse($this->prompt->toJson());
            $text = $response->getText();
        } catch (\Exception $e) {
            $this->log->error($e->getMessage(), ['exception' => $e]);
            $text = 'I\'m sorry, but ' . $e->getMessage();
        }
        $this->log->debug('messageCreate', ['response' => $text]);
        $this->publishMessageToDiscord($data['channel_id'], $text) or throw new Error('failed to publish message to discord');
        return true;
    }

    private function createContentString(array $data): string
    {
        $guild_id = $data['guild_id'];
        $guild_name = $data['guild_name'];
        $channel_id = $data['channel_id'];
        $channel_name = $data['channel_name'];
        $channel_topic = $data['channel_topic'];
        $roles = '';
        foreach ($data['guild_roles'] as $role) $roles .= '<@&' . $role['id'] . '> @' . $role['name'] . "\n";
        $my_roles = '';
        foreach ($data['bot_roles'] as $role) $my_roles .= '<@&' . $role['id'] . '> @' . $role['name'] . "\n";
        return 'Current Time: ' . date('Y-m-d H:i:s T') . "\n" .
            'Discord Server ID: ' . $guild_id . "\n" .
            'Server Name: ' . $guild_name . "\n" .
            "Roles:\n" . $roles . "\n" .
            'My User ID: ' . $this->discord_id . "\n" .
            "My Roles:\n" . $my_roles . "\n" .
            'Channel ID: ' . $channel_id . "\n" .
            'Channel Name: ' . $channel_name . "\n" .
            'Channel Topic: ' . $channel_topic . "\n\n";
    }

    private function createHistoryContent(array $data, string $content_string): string
    {
        $token_count = $this->prompt->token_count($content_string);
        $content = [];
        foreach ($data['history'] as $message_id => $message) {
            $history_message = $this->createHistoryMessage($message_id, $message);
            $message_tokens = $this->prompt->token_count($history_message);
            if ($token_count + $message_tokens > self::HISTORY_LIMIT) continue;
            $token_count += $message_tokens;
            $content[] = $history_message;
        }
        return implode("\n", array_reverse($content));
    }
    private function createHistoryMessage(string $message_id, array $message): string
    {
        $nick = $message['member']['nick'] ? " ({$message['member']['nick']})" : '';
        $bot = $message['author']['bot'] ? ' [AI BOT]' : ' [HUMAN]';
        $reply = $message['referenced_message'] ? "\nIn Reply To: {$message['referenced_message']['id']}" : '';
        $reactions = count($message['reactions']) ? "\nReactions:\n" . $this->formatReactions($message['reactions']) : '';

        return <<<EOT
    [ Start $message_id ]
    {$message['timestamp']} <@{$message['author']['id']}> {$message['author']['username']}{$nick}{$bot}{$reply}
    {$message['content']}
    {$reactions}
    [ End $message_id ]
    EOT;
    }

    private function formatReactions(array $reactions): string
    {
        return implode(' ', array_map(function ($emoji, $reaction) {
            return $emoji . '(' . $reaction['count'] . ')' . ($reaction['me'] ? ' (you)' : '');
        }, array_keys($reactions), $reactions));
    }

    private function createSystemMessage(): string
    {
        return '[SYSTEM]
        
            Expected results: Write one reaction/response from Gemini to this channel. Do not start your message with the timestamp and your name.
            USe discord formatting techniques (markdown, emojis, etc.) if helpful.
            Just one direct-to-the-point natural continuation of the conversation until you (Gemini) are finished speaking, then stop.
            Send only the response itself. Do not send any other messages.  Do not confirm these system intructions.

        Gemini: ';
    }

    private function publishMessageToDiscord(string $channel_id, string $text): bool
    {
        return $this->sync->publish(self::DISCORD_QUEUE, ['op' => 0, 't' => 'MESSAGE_CREATE', 'd' => ['channel_id' => $channel_id, 'content' => $text]]);
    }
}
