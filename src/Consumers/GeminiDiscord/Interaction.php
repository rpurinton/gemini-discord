<?php

namespace RPurinton\GeminiDiscord\Consumers\GeminiDiscord;

use RPurinton\GeminiDiscord\{
    RabbitMQ\Sync,
    Locales,
    Log,
    Error,
};

class Interaction
{
    const DISCORD_QUEUE = 'discord';
    private Log $log;
    private Sync $sync;
    private ?array $locales = null;

    public function __construct(array $config)
    {
        $this->log = $config['log'];
        $this->sync = $config['sync'];
        $this->locales = Locales::get();
    }

    public function interactionHandle(array $data): bool
    {
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
        $this->sync->publish(self::DISCORD_QUEUE, [
            'op' => 0, 't' => 'INTERACTION_HANDLE',
            'd' => ['id' => $id, 'content' => $content],
        ]) or throw new Error('failed to publish message to discord');
        return true;
    }
}
