<?php

namespace RPurinton\GeminiDiscord\Consumers;

use Bunny\{Channel, Message};
use React\EventLoop\LoopInterface;
use RPurinton\GeminiDiscord\{Locales, Log, Error, MySQL};
use RPurinton\GeminiDiscord\RabbitMQ\{Consumer, Sync};

class OpenAIClient
{
    private ?int $discord_id = null;
    private ?Log $log = null;
    private ?LoopInterface $loop = null;
    private ?Consumer $mq = null;
    private ?Sync $sync = null;
    private ?MySQL $sql = null;
    private ?string $openai_token = null;
    private ?array $locales = null;

    public function __construct(private array $config)
    {
        $this->validateConfig($config);
        $this->log = $config['log'];
        $this->loop = $config['loop'];
        $this->mq = $config['mq'];
        $this->sync = $config['sync'];
        $this->sql = $config['sql'];
        $this->log->debug('OpenAIClient::construct');
    }

    private function validateConfig(array $config): bool
    {
        $requiredKeys = [
            'log' => 'RPurinton\GeminiDiscord\Log',
            'loop' => 'React\EventLoop\LoopInterface',
            'mq' => 'RPurinton\GeminiDiscord\RabbitMQ\Consumer',
            'sync' => 'RPurinton\GeminiDiscord\RabbitMQ\Sync',
            'sql' => 'RPurinton\GeminiDiscord\MySQL'
        ];
        foreach ($requiredKeys as $key => $class) {
            if (!array_key_exists($key, $config)) throw new Error('missing required key ' . $key);
            if (!is_a($config[$key], $class)) throw new Error('invalid type for ' . $key);
        }
        return true;
    }

    public function init(): bool
    {
        $this->log->debug('OpenAIClient::init');
        $this->locales = Locales::get();
        $this->discord_id = $this->getId();
        $this->openai_token = $this->getOpenAIToken();
        $sharing_queue = 'openai';
        $private_queue = $this->log->getName();
        $this->sync->queueDeclare($sharing_queue, false) or throw new Error('failed to declare private queue');
        $this->sync->queueDeclare($private_queue, true) or throw new Error('failed to declare private queue');
        $this->mq->consume($sharing_queue, $this->callback(...)) or throw new Error('failed to connect to sharing queue');
        $this->mq->consume($private_queue, $this->callback(...)) or throw new Error('failed to connect to private queue');
        return true;
    }

    public function callback(Message $message, Channel $channel): bool
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
                return $this->messageCreate($content['d']);
            case 'INTERACTION_HANDLE':
                return $this->interactionHandle($content['d']);
        }
        return true;
    }

    private function heartbeat(array $content): bool
    {
        $this->log->debug('heartbeat', [$content]);
        $this->sql->query('SELECT 1'); // keep MySQL connection alive
        $this->sync->publish('discord', $content) or throw new Error('failed to publish message to discord');
        return true;
    }

    private function getId(): string
    {
        $result = $this->sql->query('SELECT `discord_id` FROM `discord_tokens` LIMIT 1');
        if ($result === false) throw new Error('failed to get discord_id');
        if ($result->num_rows === 0) throw new Error('no discord_id found');
        $row = $result->fetch_assoc();
        $id = $row['discord_id'] ?? null;
        $this->validateId($id) or throw new Error('invalid discord_id');
        return $id;
    }

    private function validateId(int|string $id): bool
    {
        $this->log->debug('validateId', ['id' => $id, 'type' => gettype($id)]);
        if (!is_numeric($id)) throw new Error('id is not numeric');
        if ($id < 0) throw new Error('id is negative');
        return true;
    }

    private function getOpenAIToken(): string
    {
        $result = $this->sql->query('SELECT `api_key` FROM `openai_api_keys` LIMIT 1');
        if ($result === false) throw new Error('failed to get openai_api_key');
        if ($result->num_rows === 0) throw new Error('no openai_api_key found');
        $row = $result->fetch_assoc();
        $api_key = $row['api_key'] ?? null;
        $this->validateApiKey($api_key) or throw new Error('invalid openai_api_key');
        return $api_key;
    }

    private function validateApiKey(mixed $api_key): bool
    {
        $this->log->debug('validateId', ['id' => $api_key, 'type' => gettype($api_key)]);
        return preg_match('/^sk-[a-zA-Z0-9]{48}$/', $api_key) === 1;
    }


    private function messageCreate(array $data): bool
    {
        $this->log->debug('messageCreate', ['data' => $data]);
        if (!isset($data['author']['id'], $data['content'])) return true;
        if ($data['author']['id'] == $this->discord_id) return true;
        $eval = $this->evaluate($data['content'] ?? null);
        $this->log->debug('messageCreate', ['eval' => $eval]);
        $flagged = $eval['results'][0]['flagged'] ?? false;
        if (!$flagged) return true;
        $this->log_message($data, $eval) or throw new Error('failed to log message');
        return true;
    }

    private function evaluate(?string $text): array
    {
        $this->log->debug('evaluate', ['text' => $text]);
        return json_decode(file_get_contents('https://api.openai.com/v1/moderations', false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Authorization: Bearer ' . $this->openai_token,
                    'Content-Type: application/json'
                ]),
                'content' => json_encode(array('input' => $text))
            ]
        ])), true);
    }

    private function interactionHandle(array $data): bool
    {
        $this->log->debug('interactionHandle', ['data' => $data]);
        if ($data['member']['user']['id'] == $this->discord_id) return true; // ignore interactions from self
        $locale = $this->locales[$data['locale'] ?? 'en-US'] ?? $this->locales['en-US'];
        switch ($data['data']['name']) {
            case 'help':
                return $this->help($data);
            case 'log_channel':
                return $this->logChannelSetup($data);
            case 'Evaluate':
                return $this->evaluateContext($data);
        }
        return true;
    }

    private function evaluateContext(array $data): bool
    {
        $locale = $this->locales[$data['locale'] ?? 'en-US'] ?? $this->locales['en-US'];
        $messages = $data['data']['resolved']['messages'] ?? [];
        foreach ($messages as $message_id => $message) $content = $message['content'] ?? null;
        $eval = $this->evaluate($content);
        $results = '';
        foreach ($eval['results'][0]['category_scores'] as $key => $value) {
            $score = round($value * 100) . '%';
            $key = $locale['categories'][$key] ?? $key;
            $results .= $key . ': ' . $score . "\n";
        }
        $results = trim($results);
        return $this->interactionReply($data['id'], $results);
    }

    private function help(array $data): bool
    {
        $locale = $this->locales[$data['locale'] ?? 'en-US'] ?? $this->locales['en-US'];
        return $this->interactionReply($data['id'], $locale['help']);
    }

    private function logChannelSetup(array $data): bool
    {
        $locale = $this->locales[$data['locale'] ?? 'en-US'] ?? $this->locales['en-US'];
        $admin = $data['member']['permissions']['manage_guild'] ?? false;
        if (!$admin) return $this->interactionReply($data['id'], $locale['log_channel_admin_only']);
        $guild_id = $this->sql->escape($data['guild_id'] ?? null);
        $channel_id = $this->sql->escape($data['channel_id'] ?? null);
        $guild_locale = $this->sql->escape($data['guild_locale'] ?? null);
        if (!$guild_id || !$channel_id || !$guild_locale) return $this->interactionReply($data['id'], $locale['log_channel_error']);
        $this->sql->query("INSERT INTO `log_channels` (`guild_id`, `channel_id`, `guild_locale`) VALUES ('$guild_id', '$channel_id', '$guild_locale') ON DUPLICATE KEY UPDATE `channel_id` = '$channel_id', `guild_locale` = '$guild_locale'") or throw new Error('failed to insert log channel');
        return $this->interactionReply($data['id'], $locale['log_channel_confirm'] . ' <#' . $data['channel_id'] . '>');
    }

    private function interactionReply(int $id, string $content): bool
    {
        $this->log->debug('interactionReply', ['id' => $id, 'content' => $content]);
        $this->sync->publish('discord', [
            'op' => 0, // DISPATCH
            't' => 'INTERACTION_HANDLE',
            'd' => [
                'id' => $id,
                'content' => $content,
            ]
        ]) or throw new Error('failed to publish message to discord');
        return true;
    }

    private function log_message(array $data, array $eval): bool
    {
        $guild_id = $data['guild_id'] ?? null;
        if (!$guild_id) return true;
        $this->log->debug('log_message', ['guild_id' => $guild_id]);
        $guild_id_esc = $this->sql->escape($guild_id);
        $result = $this->sql->query("SELECT `channel_id`, `guild_locale` FROM `log_channels` WHERE `guild_id` = '$guild_id_esc' LIMIT 1");
        if ($result === false || $result->num_rows === 0) return true;
        $row = $result->fetch_assoc();
        $log_channel_id = $row['channel_id'] ?? null;
        $guild_locale = $row['guild_locale'] ?? null;
        $locale = $this->locales[$guild_locale] ?? $this->locales['en-US'];
        if (!$log_channel_id) return true;
        $this->log->debug('log_message', ['channel_id' => $log_channel_id]);
        $message_id = $data['id'] ?? null;
        $author_id = $data['author']['id'] ?? null;
        $timestamp = $data['timestamp'] ?? null;
        $channel_id = $data['channel_id'] ?? null;
        $message_url = 'https://discord.com/channels/' . $guild_id . '/' . $channel_id . '/' . $message_id;
        $content = $data['content'] ?? null;
        $description = '<@' . $author_id . '> ' . $content . "\n\n";
        foreach ($eval['results'][0]['categories'] as $key => $value) {
            if ($value) {
                $score = $eval['results'][0]['category_scores'][$key] ?? -1;
                $score = round($score * 100) . '%';
                $key = $locale['categories'][$key] ?? $key;
                $description .= $key . ': ' . $score . "\n";
            }
        }
        $description = trim($description);
        $this->sync->publish('discord', [
            'op' => 0, // DISPATCH
            't' => 'MESSAGE_CREATE',
            'd' => [
                'channel_id' => $log_channel_id,
                'embeds' => [
                    [
                        'type' => 'rich',
                        'title' => $message_url,
                        'color' => 0xff0000,
                        'url' => $message_url,
                        'description' => $description,
                        'timestamp' => $timestamp,
                    ]
                ]
            ]
        ]) or throw new Error('failed to publish message to discord');
        return true;
    }

    public function __destruct()
    {
        $this->log->debug('OpenAIClient::__destruct');
    }
}
