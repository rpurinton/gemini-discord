<?php

namespace RPurinton\GeminiDiscord\Consumers\GeminiDiscord;

use RPurinton\GeminiDiscord\{
    Consumers\GeminiDiscord\GeminiConfig,
    MySQL,
    Error,
};

class Init
{
    const GEMINI_QUEUE = 'gemini';
    private int $discord_id;

    public function __construct(private GeminiConfig $config)
    {
        $config->log->debug('Init::__construct');
    }

    public function init(callable $callback): int|bool
    {
        $this->config->log->debug('GeminiClient::init');
        $this->discord_id = $this->getId();
        $private_queue = $this->config->log->getName();
        $this->config->sync->queueDeclare(self::GEMINI_QUEUE, false) or throw new Error('failed to declare gemini queue');
        $this->config->sync->queueDeclare($private_queue, true) or throw new Error('failed to declare private queue');
        $this->config->mq->consume(self::GEMINI_QUEUE, $callback) or throw new Error('failed to connect to gemini queue');
        $this->config->mq->consume($private_queue, $callback) or throw new Error('failed to connect to private queue');
        return $this->discord_id;
    }


    private function getId(): string
    {
        $result = $this->config->sql->query('SELECT `discord_id` FROM `discord_tokens` LIMIT 1');
        if ($result === false) throw new Error('failed to get discord_id');
        if ($result->num_rows === 0) throw new Error('no discord_id found');
        $row = $result->fetch_assoc();
        $id = $row['discord_id'] ?? null;
        $this->validateId($id) or throw new Error('invalid discord_id');
        return $id;
    }

    public static function validateId(int|string $id): bool
    {
        if (!is_numeric($id)) throw new Error('id is not numeric');
        if ($id < 0) throw new Error('id is negative');
        return true;
    }
}
