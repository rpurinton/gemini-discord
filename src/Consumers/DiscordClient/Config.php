<?php

namespace RPurinton\GeminiDiscord\Consumers\DiscordClient;

use React\EventLoop\LoopInterface;
use RPurinton\GeminiDiscord\{
    Error,
    Log,
    MySQL,
    RabbitMQ\Consumer,
    RabbitMQ\Publisher
};

class Config
{
    public Log $log;
    public LoopInterface $loop;
    public Consumer $mq;
    public Publisher $pub;
    public MySQL $sql;
    public ?string $token = null;

    public function __construct(private array $config)
    {
        $this->validateConfig($config);
        $this->log = $config['log'];
        $this->loop = $config['loop'];
        $this->mq = $config['mq'];
        $this->pub = $config['pub'];
        $this->sql = $config['sql'];
    }

    private function validateConfig(array $config): bool
    {
        $requiredKeys = [
            'log' => 'RPurinton\GeminiDiscord\Log',
            'loop' => 'React\EventLoop\LoopInterface',
            'mq' => 'RPurinton\GeminiDiscord\RabbitMQ\Consumer',
            'pub' => 'RPurinton\GeminiDiscord\RabbitMQ\Publisher',
            'sql' => 'RPurinton\GeminiDiscord\MySQL'
        ];
        foreach ($requiredKeys as $key => $class) {
            if (!array_key_exists($key, $config)) throw new Error('missing required key ' . $key);
            if (!is_a($config[$key], $class)) throw new Error('invalid type for ' . $key);
        }
        return true;
    }
}
