<?php

namespace RPurinton\GeminiDiscord\Consumers\DiscordClient;

use Bunny\{
    Channel,
    Message as RabbitMessage
};
use Discord\{
    Discord,
    Parts\User\Activity,
    Parts\Interactions\Command\Command,
};
use React\Async;
use RPurinton\GeminiDiscord\{
    RabbitMQ\Consumer,
    RabbitMQ\Publisher,
    Commands,
    Error,
    Log,
};

class Callback
{
    const DISCORD_QUEUE = 'discord';
    const GEMINI_QUEUE = 'gemini';
    private Log $log;
    private Publisher $pub;
    private Consumer $mq;
    private Discord $discord;
    private Raw $raw;
    private Interaction $interaction;
    private Message $message;

    public function __construct(array $config)
    {
        $this->log = $config['log'];
        $this->pub = $config['pub'];
        $this->mq = $config['mq'];
    }

    public function init(array $config): void
    {
        $this->discord = $config['discord'];
        $this->raw = $config['raw'];
        $this->interaction = $config['interaction'];
        $this->message = $config['message'];
    }

    public function ready()
    {
        $this->pub->queueDeclare(self::DISCORD_QUEUE, false) or throw new Error('failed to declare discord queue');
        $this->mq->consume(self::DISCORD_QUEUE, $this->callback(...)) or throw new Error('failed to connect to discord queue');
        $activity = $this->discord->factory(Activity::class, [
            'name' => 'AI Language Model',
            'type' => Activity::TYPE_PLAYING
        ]);
        $this->discord->updatePresence($activity);
        //$old_cmds = Async\await($this->discord->application->commands->freshen());
        //foreach ($old_cmds as $old_cmd) Async\await($this->discord->application->commands->delete($old_cmd));
        foreach (Commands::get() as $command) {
            $slashcommand = new Command($this->discord, $command);
            $this->discord->application->commands->save($slashcommand);
            $this->discord->listenCommand($command['name'], $this->interaction->interaction(...));
        }
        $this->discord->on('raw', $this->raw->raw(...));
        $this->log->info('DiscordClient is ready!');
    }


    public function callback(RabbitMessage $message, Channel $channel): bool
    {
        $content = json_decode($message->content, true);
        $this->route($content) or throw new Error('failed to route message');
        $channel->ack($message);
        return true;
    }

    private function route(array $content): bool
    {
        $this->log->debug('route', ['op' => $content['op'], 't' => $content['t']]);
        switch ($content['t']) {
            case 'MESSAGE_CREATE':
                return $this->message->messageCreate($content['d']);
            case 'INTERACTION_HANDLE':
                return $this->interaction->interactionHandle($content['d']);
            default:
                return true;
        }
    }
}
