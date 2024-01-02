#!/usr/bin/env php
<?php

use RPurinton\GeminiPHP\{GeminiClient, GeminiPrompt};
use RPurinton\GeminiDiscord\Config;

require_once __DIR__ . '/../vendor/autoload.php';

$client = new GeminiClient(Config::get('gemini'));
$prompt = new GeminiPrompt(Config::get('prompt'));
while (true) {
    $input = readline('user> ');
    switch ($input) {
        case '':
            break;
        case 'exit':
            break 2;
        case 'clear':
            $prompt->resetContent();
            break;
        case 'help':
            echo 'Commands: exit, clear, help' . PHP_EOL;
            break;
        default:
            echo 'gemini...';
            $prompt->push(['role' => 'user', 'parts' => ['text' => $input]]);
            $response = $client->getResponse($prompt->toJson());
            print_r($response);
            $text = $response->getText();
            $prompt->push(['role' => 'assistant', 'parts' => ['text' => $text]]);
            echo "\r       \rgemini> $text\n";
            break;
    }
}
