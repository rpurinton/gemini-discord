<?php

namespace RPurinton\GeminiDiscord\Consumers\DiscordClient;

use Discord\Discord;
use RPurinton\GeminiDiscord\Log;

class Message
{
    private Discord $discord;

    public function __construct(private Log $log)
    {
    }

    public function init(Discord $discord): void
    {
        $this->discord = $discord;
    }

    private function splitMessage(string $content): array
    {
        $lines = explode('\n', $content . ' ');
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
                    $result .= $line . '\n';
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

    private function sendMessage(array $message): bool
    {
        $this->discord->getChannel($message['channel_id'])->sendMessage($this->builder($message));
        return true;
    }

    public function messageCreate(array $message): bool
    {
        if ($this->blankMessage($message)) {
            return $this->sendMessage(['channel_id' => $message['channel_id'], 'content' => 'No response (possibly censored)']);
        }

        if (empty($message['content']) || strlen($message['content']) < 2000) {
            return $this->sendMessage($message);
        }

        $content = $message['content'];
        while (!empty($content)) {
            $split = $this->splitMessage($content);
            $content = implode($split['mode'] === 'by_char' ? '' : ' ', $split['remaining']);
            $message['content'] = $split['content'];
            $this->sendMessage($message);
        }

        return true;
    }

    public function blankMessage($message): bool
    {
        $hasEmbeds = count($message['embeds'] ?? []) > 0;
        $hasAttachments = count($message['attachments'] ?? []) > 0;
        $hasContent = strlen($message['content'] ?? '') > 0;
        return !($hasEmbeds || $hasAttachments || $hasContent);
    }

    public function builder($message)
    {
        $builder = \Discord\Builders\MessageBuilder::new();
        //$builder->setTts(true);
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
}
