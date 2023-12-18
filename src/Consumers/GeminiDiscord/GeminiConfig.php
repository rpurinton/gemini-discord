<?php

namespace RPurinton\GeminiDiscord\Consumers\GeminiDiscord;

use RPurinton\GeminiPHP\GeminiClient;
use RPurinton\GeminiDiscord\{
    Error,
    Log,
    MySQL,
    RabbitMQ\Consumer,
    RabbitMQ\Sync,
};

class GeminiConfig
{
    public ?Log $log = null;
    public ?Consumer $mq = null;
    public ?Sync $sync = null;
    public ?MySQL $sql = null;
    public ?GeminiClient $gemini = null;

    public function __construct(private array $config)
    {
        $this->validateConfig($config);
        $this->log = $config['log'];
        $this->mq = $config['mq'];
        $this->sync = $config['sync'];
        $this->sql = $config['sql'];
        $this->gemini = $config['gemini'];
        $this->log->debug('GeminiConfig::construct');
    }

    private function validateConfig(array $config): bool
    {
        $requiredKeys = [
            'log' => 'RPurinton\GeminiDiscord\Log',
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
}
