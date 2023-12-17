<?php

namespace RPurinton\GeminiDiscord\Consumers;

use Bunny\{Channel, Message};
use Discord\{Discord, WebSockets\Intents};
use Discord\Parts\User\Activity;
use Discord\Parts\Interactions\{Interaction, Command\Command};
use React\{Async, EventLoop\LoopInterface};
use RPurinton\GeminiDiscord\{Commands, Log, Error, MySQL};
use RPurinton\GeminiDiscord\RabbitMQ\{Consumer, Publisher};
use stdClass;

class DiscordClient
{
    private ?string $token = null;
    private ?Discord $discord = null;
    private ?Log $log = null;
    private ?LoopInterface $loop = null;
    private ?Consumer $mq = null;
    private ?Publisher $pub = null;
    private ?MySQL $sql = null;
    private array $interactions = [];

    public function __construct(private array $config)
    {
        $this->validateConfig($config);
        $this->log = $config['log'];
        $this->loop = $config['loop'];
        $this->mq = $config['mq'];
        $this->pub = $config['pub'];
        $this->sql = $config['sql'];
        $this->log->debug('construct');
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

    public function init(): bool
    {
        $this->log->debug('init');
        $this->token = $this->getToken();
        $discord_config = [
            'token' => $this->token,
            'logger' => $this->log,
            'loop' => $this->loop,
            'intents' => Intents::getDefaultIntents()
        ];
        $this->discord = new Discord($discord_config);
        $this->discord->on('ready', $this->ready(...));
        $this->discord->run();
        return true;
    }

    private function ready()
    {
        $sharing_queue = 'discord';
        $this->pub->queueDeclare($sharing_queue, false) or throw new Error('failed to declare private queue');
        $this->mq->consume('discord', $this->callback(...)) or throw new Error('failed to connect to queue');
        $activity = $this->discord->factory(Activity::class, [
            'name' => 'AI Language Model',
            'type' => Activity::TYPE_PLAYING
        ]);
        $this->discord->updatePresence($activity);
        $this->discord->on('raw', $this->raw(...));
        //$old_cmds = Async\await($this->discord->application->commands->freshen());
        //foreach ($old_cmds as $old_cmd) Async\await($this->discord->application->commands->delete($old_cmd));
        foreach (Commands::get() as $command) {
            $slashcommand = new Command($this->discord, $command);
            $this->discord->application->commands->save($slashcommand);
            $this->discord->listenCommand($command["name"], $this->interaction(...));
        }
    }

    private function interaction(Interaction $interaction): void
    {
        $this->log->debug('interaction', ['interaction' => $interaction]);
        $this->interactions[$interaction->id] = $interaction;
        $message = [
            "op" => 0,
            "t" => 'INTERACTION_HANDLE',
            "d" => json_decode(json_encode($interaction), true)
        ];
        $this->pub->publish("gemini", $message);
        $interaction->acknowledgeWithResponse(true);
    }

    private function raw(stdClass $message, Discord $discord): bool // from Discord\Discord::onRaw
    {
        $this->log->debug('raw', ['message' => $message]);
        if ($this->heartbeat($message)) return true;
        if (!$this->relevant($message)) return true;
        if (!$this->allowed($message)) return true;
        $this->pub->publish('gemini', $this->getPublishMessage($message)) or throw new Error('failed to publish message to gemini');
        return true;
    }

    private function heartbeat(stdClass $message): bool
    {
        if ($message->op !== 11) return false;
        $this->sql->query('SELECT 1'); // heartbeat / keep MySQL connection alive
        $this->pub->publish('broadcast', $message) or throw new Error('failed to publish message to gemini');
        return true;
    }

    private function relevant(stdClass $message): bool
    {
        $relevant_types = ['MESSAGE_CREATE'];
        if (!in_array($message->t, $relevant_types)) return false;
        if (!isset($message->d->author->id, $message->d->content)) return false;
        if ($message->d->author->id == $this->discord->id) return false;
        if (empty($message->d->content)) return false;
        if (isset($message->d->author->bot) && $message->d->author->bot === true) return false;

        if (isset($message->d->referenced_message) && $message->d->referenced_message->author->id == $this->discord->id) return true;
        if (strpos($message->d->content, '<@' . $this->discord->id . '>') !== false) return true;

        return false;
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

    private function getPublishMessage($message): array
    {
        $channel = $message->d->channel;
        $publish_message = json_decode(json_encode($message), true);
        $publish_message["d"]["history"] = Async\await($channel->getMessageHistory(['limit' => 100]));
        $publish_message["d"]["guild_name"] = $channel->guild->name;
        $publish_message["d"]["channel_name"] = $channel->name;
        $publish_message["d"]["channel_topic"] = $channel->topic;
        return $publish_message;
    }

    public function callback(Message $message, Channel $channel): bool // from RabbitMQ\Consumer::connect
    {
        $this->log->debug('callback', [$message->content]);
        $content = json_decode($message->content, true);
        if ($content['op'] === 11) $this->log->debug('heartbeat circuit complete');
        else $this->route($content) or throw new Error('failed to route message');
        $channel->ack($message);
        return true;
    }

    private function route(array $content): bool
    {
        $this->log->debug('route', [$content['t']]);
        switch ($content['t']) {
            case 'MESSAGE_CREATE':
                return $this->messageCreate($content['d']);
            case 'INTERACTION_HANDLE':
                return $this->interactionHandle($content['d']);
        }
        return true;
    }

    private function splitMessage(string $content): array
    {
        $lines = explode("\n", $content . ' ');
        $mode = 'by_line';
        $result = '';
        while (count($lines)) {
            $line = array_shift($lines);
            if (strlen($line) > 2000) {
                if ($mode === 'by_line') {
                    $sentences = explode('. ', $line);
                    $mode = 'by_sentence';
                    foreach ($lines as $line) {
                        $sentences[] = $line;
                    }
                    $lines = $sentences;
                    $line = array_shift($lines);
                }
                if (strlen($line) > 2000 && $mode === 'by_sentence') {
                    $words = explode(' ', $line);
                    $mode = 'by_word';
                    foreach ($lines as $line) {
                        $words[] = $line;
                    }
                    $lines = $words;
                    $line = array_shift($lines);
                }
                if (strlen($line) > 2000 && $mode === 'by_word') {
                    $chars = str_split($line);
                    $mode = 'by_char';
                    foreach ($lines as $line) {
                        $chars[] = $line;
                    }
                    $lines = $chars;
                    $line = array_shift($lines);
                }
            }
            $old_result = $result;
            switch ($mode) {
                case 'by_char':
                    $result .= $line;
                    break;
                case 'by_word':
                    $result .= $line . ' ';
                    break;
                case 'by_sentence':
                    $result .= $line . '. ';
                    break;
                case 'by_line':
                    $result .= $line . "\n";
                    break;
            }
            if (strlen($result) > 2000) {
                $result = $old_result;
                array_unshift($lines, $line);
                if (substr($result, -1) == ' ') {
                    $result = substr($result, 0, -1);
                }
                return ['content' => $result, 'remaining' => $lines, 'mode' => $mode];
            }
        }
        return ['content' => $result, 'remaining' => [], 'mode' => $mode];
    }


    private function sendMessage(array $message): void
    {
        $this->discord->getChannel($message['channel_id'])->sendMessage($this->builder($message));
    }

    private function messageCreate(array $message): bool
    {
        $this->log->debug('messageCreate', ['message' => $message]);
        if (!isset($message['content']) || strlen($message['content']) < 2000) {
            $this->sendMessage($message);
            return true;
        }
        $content = $message['content'];
        while (strlen($content)) {
            $split = $this->splitMessage($content);
            $content = implode($split['mode'] === 'by_char' ? '' : ' ', $split['remaining']);
            $message['content'] = $split['content'];
            $this->sendMessage($message);
        }
        return true;
    }

    private function interactionHandle(array $interactionReply): bool
    {
        $this->log->debug('interactionHandle', ['interaction' => $interactionReply]);
        $this->interactions[$interactionReply['id']]?->updateOriginalResponse($this->builder($interactionReply));
        unset($this->interactions[$interactionReply['id']]);
        return true;
    }

    private function builder($message)
    {
        $builder = \Discord\Builders\MessageBuilder::new();
        $this->setContent($builder, $message);
        $this->addFileFromContent($builder, $message);
        $this->addAttachments($builder, $message);
        $this->addEmbeds($builder, $message);
        $this->setAllowedMentions($builder, $message);
        return $builder;
    }

    private function setContent($builder, $message)
    {
        if (isset($message['content'])) $builder->setContent($message['content']);
    }

    private function addFileFromContent($builder, $message)
    {
        if (isset($message['addFileFromContent'])) foreach ($message['addFileFromContent'] as $attachment) $builder->addFileFromContent($attachment['filename'], $attachment['content']);
    }

    private function addAttachments($builder, $message)
    {
        if (isset($message['attachments'])) foreach ($message['attachments'] as $attachment) {
            $embed = new \Discord\Parts\Embed\Embed($this->discord);
            $embed->setURL($attachment['url']);
            $embed->setImage($attachment['url']);
            $builder->addEmbed($embed);
        }
    }

    private function addEmbeds($builder, $message)
    {
        if (isset($message['embeds'])) foreach ($message['embeds'] as $old_embed) if ($old_embed['type'] == 'rich') {
            $new_embed = new \Discord\Parts\Embed\Embed($this->discord);
            $new_embed->fill($old_embed);
            $builder->addEmbed($new_embed);
        }
    }

    private function setAllowedMentions($builder, $message)
    {
        if (isset($message['mentions'])) {
            $allowed_users = array();
            foreach ($message['mentions'] as $mention) $allowed_users[] = $mention['id'];
            $allowed_mentions['parse'] = array('roles', 'everyone');
            $allowed_mentions['users'] = $allowed_users;
            $builder->setAllowedMentions($allowed_mentions);
        }
    }

    private function getToken(): string
    {
        $this->log->debug('getToken');
        $result = $this->sql->query('SELECT `discord_token` FROM `discord_tokens` LIMIT 1');
        if ($result === false) throw new Error('failed to get discord_token');
        if ($result->num_rows === 0) throw new Error('no discord_token found');
        $row = $result->fetch_assoc();
        $token = $row['discord_token'];
        $this->validateToken($token);
        return $token;
    }

    private function validateToken($token)
    {
        $this->log->debug('validateToken');
        if (!is_string($token)) throw new Error('token is not a string');
        if (strlen($token) === 0) throw new Error('token is empty');
        if (strlen($token) !== 72) throw new Error('token is not 72 characters');
        return true;
    }
}
