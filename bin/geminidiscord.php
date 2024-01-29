#!/usr/bin/env php
<?php

namespace RPurinton\GeminiDiscord;

use React\EventLoop\Loop;
use RPurinton\GeminiPHP\{GeminiClient, GeminiPrompt};
use RPurinton\GeminiDiscord\{
    RabbitMQ\Consumer,
    RabbitMQ\Sync,
    Consumers\GeminiDiscord,
    Config,
};
use RPurinton\GeminiDiscord\Consumers\GeminiDiscord\{
    GeminiConfig,
    Init,
    Interaction,
    Message,
    Raw,
    Callback,
};

$worker_id = $argv[1] ?? 0;

// enable all errors for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    require_once __DIR__ . '/../Composer.php';
    $log = LogFactory::create('GeminiClient-' . $worker_id) or throw new Error('failed to create log');
    set_exception_handler(function ($e) use ($log) {
        $log->error($e->getMessage(), ['trace' => $e->getTrace()]);
        exit(1);
    });
} catch (\Exception $e) {
    echo ('Fatal Exception ' . $e->getMessage() . '\n');
    exit(1);
} catch (\Throwable $e) {
    echo ('Fatal Throwable ' . $e->getMessage() . '\n');
    exit(1);
} catch (\Error $e) {
    echo ('Fatal Error ' . $e->getMessage() . '\n');
    exit(1);
}

$loop = Loop::get();
$config = new GeminiConfig([
    'log' => $log,
    'mq' => new Consumer($log, $loop),
    'sync' => new Sync($log),
    'sql' => new MySQL($log),
    'gemini' => new GeminiClient(Config::get('gemini'))
]) or throw new Error('failed to create Consumer');
$gemini = new GeminiDiscord([
    'config' => $config,
    'init' => new Init($config),
    'interaction' => new Interaction([
        'log' => $config->log,
        'sync' => $config->sync,
    ]),
    'message' => new Message([
        'log' => $config->log,
        'sync' => $config->sync,
        'gemini' => $config->gemini,
        'prompt' => new GeminiPrompt(Config::get('prompt')),
    ]),
    'callback' => new Callback([
        'log' => $config->log,
        'sync' => $config->sync,
        'sql' => $config->sql,
    ]),
]) or throw new Error('failed to create DiscordClient');
$gemini->init() or throw new Error('failed to initialize Consumer');
$loop->addSignal(SIGINT, function () use ($loop, $log) {
    $log->info('SIGINT received, exiting...');
    $loop->stop();
});
$loop->addSignal(SIGTERM, function () use ($loop, $log) {
    $log->info('SIGTERM received, exiting...');
    $loop->stop();
});
