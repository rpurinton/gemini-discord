<?php

namespace RPurinton\GeminiDiscord\Consumers;

use Bunny\{Channel, Message};
use React\EventLoop\LoopInterface;
use RPurinton\GeminiPHP\GeminiClient;
use RPurinton\GeminiPHP\GeminiPrompt;
use RPurinton\GeminiDiscord\{Config, Locales, Log, Error, MySQL};
use RPurinton\GeminiDiscord\RabbitMQ\{Consumer, Sync};

class GeminiDiscord
{
    private ?int $discord_id = null;
    private ?Log $log = null;
    private ?LoopInterface $loop = null;
    private ?Consumer $mq = null;
    private ?Sync $sync = null;
    private ?MySQL $sql = null;
    private ?array $locales = null;
    private ?GeminiClient $ai = null;
    private ?array $prompt = null;

    public function __construct(private array $config)
    {
        $this->validateConfig($config);
        $this->log = $config['log'];
        $this->loop = $config['loop'];
        $this->mq = $config['mq'];
        $this->sync = $config['sync'];
        $this->sql = $config['sql'];
        $this->ai = $config['gemini'];
        $this->log->debug('geminiClient::construct');
    }

    private function validateConfig(array $config): bool
    {
        $requiredKeys = [
            'log' => 'RPurinton\GeminiDiscord\Log',
            'loop' => 'React\EventLoop\LoopInterface',
            'mq' => 'RPurinton\GeminiDiscord\RabbitMQ\Consumer',
            'sync' => 'RPurinton\GeminiDiscord\RabbitMQ\Sync',
            'sql' => 'RPurinton\GeminiDiscord\MySQL',
            'gemini' => 'RPurinton\GeminiPHP\GeminiClient'
        ];
        foreach ($requiredKeys as $key => $class) {
            if (!array_key_exists($key, $config)) throw new Error('missing required key ' . $key);
            if (!is_a($config[$key], $class)) throw new Error('invalid type for ' . $key);
        }
        return true;
    }

    public function init(): bool
    {
        $this->log->debug('GeminiClient::init');
        $this->locales = Locales::get();
        $this->prompt = Config::get('prompt');
        $this->discord_id = $this->getId();
        $sharing_queue = 'gemini';
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

    private function messageCreate(array $data): bool
    {
        $this->log->debug('messageCreate', ['data' => $data]);
        $content = $data['content'];
        $content = str_replace('<@' . $this->discord_id . '>', '', $content);
        $base_content = $this->prompt['content'];
        $base_content[] = ['role' => 'user', 'parts' => ['text' => $content]];
        $prompt = new GeminiPrompt($this->prompt['generation_config'], $base_content, $this->prompt['safety_settings'], $this->prompt['tools']);
        $response = $this->ai->getResponse($prompt->toJson());
        $text = $response->getText();
        $this->log->debug('messageCreate', ['text' => $text]);
        if (empty($text)) return true;
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

    private function interactionHandle(array $data): bool
    {
        $this->log->debug('interactionHandle', ['data' => $data]);
        if ($data['member']['user']['id'] == $this->discord_id) return true; // ignore interactions from self
        $locale = $this->locales[$data['locale'] ?? 'en-US'] ?? $this->locales['en-US'];
        switch ($data['data']['name']) {
            case 'help':
                return $this->help($data);
        }
        return true;
    }

    private function help(array $data): bool
    {
        $locale = $this->locales[$data['locale'] ?? 'en-US'] ?? $this->locales['en-US'];
        return $this->interactionReply($data['id'], $locale['help']);
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

    public function __destruct()
    {
        $this->log->debug('geminiClient::__destruct');
    }
}
