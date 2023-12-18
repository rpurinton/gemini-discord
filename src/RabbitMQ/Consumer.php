<?php

namespace RPurinton\GeminiDiscord\RabbitMQ;

use RPurinton\GeminiDiscord\{Config, Log, Error};
use React\{Async, EventLoop\LoopInterface};
use Bunny\{Async\Client, Channel};

class Consumer
{
    private ?Client $client = null;
    private ?Channel $channel = null;
    private array $consumers = [];

    public function __construct(private Log $log, private LoopInterface $loop)
    {
        $this->client = new Client($this->loop, Config::get('rabbitmq')) or throw new Error('Failed to establish the client');
        $this->client = Async\await($this->client->connect()) or throw new Error('Failed to establish the connection');
        $this->channel = Async\await($this->client->channel()) or throw new Error('Failed to establish the channel');
        $this->channel->qos(0, 1) or throw new Error('Failed to set the QoS');
    }

    public function consume(string $queue, callable $process): mixed
    {
        $consumerTag = bin2hex(random_bytes(8));
        $this->consumers[$consumerTag] = $queue;
        //$this->channel->queueDeclare($queue, false, false, false, true) or throw new Error('Failed to declare the queue');
        return Async\await($this->channel->consume($process, $queue, $consumerTag)) or throw new Error('Failed to consume the queue');
    }

    public function disconnect(): bool
    {
        if (isset($this->channel)) {
            foreach ($this->consumers as $consumerTag => $queue) {
                $this->channel->cancel($consumerTag);
                $this->channel->queueDelete($queue);
            }
            $this->channel->close();
        }
        if (isset($this->client)) {
            $this->client->disconnect();
        }
        return true;
    }

    public function __destruct()
    {
        $this->disconnect() or throw new Error('Failed to disconnect from RabbitMQ');
    }
}
