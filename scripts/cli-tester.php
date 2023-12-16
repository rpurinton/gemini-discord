<?php

use PHPUnit\Event\Runtime\PHP;
use RPurinton\GeminiPHP\{GeminiClient, GeminiPrompt};

require_once __DIR__ . '/../vendor/autoload.php';

$projectId = 'ai-project-408017';
$regionName = 'us-east4';
$credentialsPath = '/root/.google/ai-project-408017-7382b3944223.json';
$modelName = 'gemini-pro'; // or 'gemini-pro-vision'

// Initialize the Gemini client
$client = new GeminiClient($projectId, $regionName, $credentialsPath, $modelName);

// Create a prompt object
$generationConfig = [
    'temperature' => 0.986,
    'topP' => 0.986,
    'topK' => 39,
    'maxOutputTokens' => 2048,
];

$safetySettings = [
    'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
    'threshold' => 'BLOCK_NONE'
];

$tools = [];
$history = [];
$cli_prompt = '> ';

while (true) {
    $input = readline($cli_prompt);
    switch ($input) {
        case '':
            break;
        case 'exit':
            break 2;
        case 'clear':
            $history = [];
            break;
        case 'help':
            echo 'Commands: exit, clear, help' . PHP_EOL;
            break;
        default:
            $history[] = ['role' => 'user', 'parts' => ['text' => $input]];
            $prompt = new GeminiPrompt($generationConfig, $history, $safetySettings, $tools);
            $response = $client->getResponse($prompt->toJson());
            $text = $response->getText();
            $history[] = ['role' => 'assistant', 'parts' => ['text' => $text]];
            echo $text . PHP_EOL;
            break;
    }
}
