#!/usr/bin/env php
<?php

use RPurinton\GeminiPHP\{GeminiClient, GeminiPrompt};
use RPurinton\GeminiDiscord\Config;

require_once __DIR__ . '/../vendor/autoload.php';

$client = new GeminiClient(Config::get('gemini'));
$prompt = new GeminiPrompt(Config::get('prompt'));
$prompt_reset = function () use ($prompt) {
    $prompt->setContent([['role' => 'assistant', 'parts' => [['text' => 'I am Gemini.']]]]);
};
$prompt_reset();
$cli_prompt = 'user> ';
while (true) {
    $input = readline($cli_prompt);
    switch ($input) {
        case '':
            break;
        case 'exit':
            break 2;
        case 'clear':
            $prompt_reset();
            break;
        case 'help':
            echo 'Commands: exit, clear, help' . PHP_EOL;
            break;
        default:
            echo 'gemini...';
            $prompt->push(['role' => 'user', 'parts' => ['text' => $input]]);
            $response = $client->getResponse($prompt->toJson());
            $text = $response->getText();
            $prompt->push(['role' => 'assistant', 'parts' => ['text' => $text]]);
            echo "\r       \rgemini> $text\n";
            break;
    }
}
