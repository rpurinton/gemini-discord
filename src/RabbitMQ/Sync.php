<?php

namespace RPurinton\GeminiDiscord\RabbitMQ;

class Sync extends Publisher
{
    const MAX_RETRIES = 500;
    const SLEEP_TIME = 20000;
    const SLEEP_MULTIPLIER = 1;

    public function sync(string $queue, array $input)
    {
        $input["callback"] = $this->generate_random_queue_name();
        $this->channel->queueDeclare($input["callback"], false, false, false, false);
        $this->publish($queue, $input);
        $response = $this->retryGetResponse($input["callback"]);
        $this->channel->queueDelete($input["callback"]);
        return $response;
    }

    private function retryGetResponse(string $callback)
    {
        $retry = 0;
        while ($retry < self::MAX_RETRIES) {
            $retry++;
            $reply = $this->channel->get($callback);
            if ($reply) {
                $response = json_decode($reply->content, true);
                if (!$response) {
                    $this->log->warning('Failed to decode response', ['response' => $reply->content]);
                    $response = [];
                }
                $this->channel->ack($reply);
                return $response;
            } else usleep(self::SLEEP_TIME * self::SLEEP_MULTIPLIER * $retry);
        }
        $this->log->warning('Timeout waiting for response');
        return [];
    }

    private function generate_random_queue_name(): string
    {
        return 'sync_' . bin2hex(random_bytes(16));
    }
}
