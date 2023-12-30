<?php

namespace RPurinton\GeminiDiscord\Consumers\DiscordClient;

use stdClass;
use Discord\Discord;
use React\Async;
use RPurinton\GeminiDiscord\{
    Error,
    Log,
    MySQL,
    RabbitMQ\Publisher
};

class Raw
{
    const GEMINI_QUEUE = 'gemini';
    private Log $log;
    private MySQL $sql;
    private Publisher $pub;

    public function __construct(array $config)
    {
        $this->log = $config['log'];
        $this->sql = $config['sql'];
        $this->pub = $config['pub'];
    }

    public function raw(stdClass $message, Discord $discord): bool // from Discord\Discord::onRaw
    {
        if ($this->heartbeat($message)) return true;
        if (!$this->relevant($message, $discord)) return true;
        if (!$this->allowed($message)) return true;
        $discord->getChannel($message->d->channel_id)->broadcastTyping() or throw new Error('failed to broadcast typing');
        $this->pub->publish(self::GEMINI_QUEUE, $this->getPublishMessage($message, $discord)) or throw new Error('failed to publish message to gemini');
        return true;
    }

    private function heartbeat(stdClass $message): bool
    {
        if ($message->op !== 11) return false;
        $this->sql->query('SELECT 1'); // heartbeat / keep MySQL connection alive
        $this->pub->publish('broadcast', $message) or throw new Error('failed to publish broadcast message to gemini');
        return true;
    }

    private function relevant(stdClass $message, Discord $discord): bool
    {
        $relevant_types = ['MESSAGE_CREATE'];
        if (!in_array($message->t, $relevant_types)) return false;
        if (!isset($message->d->author->id, $message->d->content)) return false;
        if ($message->d->author->id == $discord->id) return false;
        if (empty($message->d->content)) return false;
        if (isset($message->d->referenced_message) && $message->d->referenced_message->author->id == $discord->id) return true;
        if (strpos($message->d->content, '<@' . $discord->id . '>') !== false) return true;
        if (strpos($message->d->content, '<@!' . $discord->id . '>') !== false) return true;
        if (strpos($message->d->content, '<@&' . $discord->id . '>') !== false) return true;
        if (strpos(strtolower($this->stripCommas($message->d->content)), 'hey gemini') !== false) return true;
        $bot_roles = $discord->guilds[$message->d->guild_id]->members[$discord->id]->roles;
        foreach ($bot_roles as $role) if (strpos($message->d->content, '<@&' . $role->id . '>') !== false) return true;
        return false;
    }

    private function stripCommas(string $content): string
    {
        return str_replace(',', '', $content);
    }

    private function allowed(stdClass $message): bool
    {
        $guild_id = $message->d->guild_id;
        $member_roles = implode(',', $message->d->member->roles);
        $request = $this->sql->query("SELECT count(1) as `allowed` FROM `allowed_roles` WHERE `guild_id` = '$guild_id' AND `role_id` IN ($member_roles)");
        if ($request === false) throw new Error('failed to get allowed roles');
        $row = $request->fetch_assoc();
        return $row['allowed'] > 0;
    }

    private function getPublishMessage(stdClass $message, Discord $discord): array
    {
        $guild = $discord->guilds[$message->d->guild_id];
        $channel = $guild->channels[$message->d->channel_id];
        $author = $guild->members[$message->d->author->id];
        $publish_message = json_decode(json_encode($message), true);
        $publish_message['d']['guild_name'] = $guild->name;
        $publish_message['d']['guild_roles'] = $guild->roles;
        $publish_message['d']['channel_name'] = $channel->name;
        $publish_message['d']['channel_topic'] = $channel->topic;
        $publish_message['d']['bot_roles'] = $guild->members[$discord->id]->roles;
        $publish_message['d']['history'] = Async\await($channel->getMessageHistory(['limit' => 100]));
        return $publish_message;
    }
}
