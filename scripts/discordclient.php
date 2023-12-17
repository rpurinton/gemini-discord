#!/usr/bin/env php
<?php

namespace RPurinton\GeminiDiscord;

use React\EventLoop\Loop;
use RPurinton\GeminiDiscord\{
    RabbitMQ\Consumer,
    RabbitMQ\Publisher,
    Consumers\DiscordClient,
};
use RPurinton\GeminiDiscord\Consumers\DiscordClient\{
    Config,
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
    $log = LogFactory::create('DiscordClient-' . $worker_id) or throw new Error('failed to create log');
    set_exception_handler(function ($e) use ($log) {
        $log->debug($e->getMessage(), ['trace' => $e->getTrace()]);
        $log->error($e->getMessage());
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
$config = new Config([
    'log' => $log,
    'loop' => $loop,
    'mq' => new Consumer($log, $loop),
    'pub' => new Publisher($log),
    'sql' => new MySQL($log)
]) or throw new Error('failed to create DiscordClient config');
$dc = new DiscordClient([
    'config' => $config,
    'init' => new Init($config),
    'interaction' => new Interaction([
        'log' => $config->log,
        'pub' => $config->pub,
    ]),
    'message' => new Message($log),
    'raw' => new Raw([
        'log' => $config->log,
        'sql' => $config->sql,
        'pub' => $config->pub,
    ]),
    'callback' => new Callback([
        'log' => $config->log,
        'pub' => $config->pub,
        'mq' => $config->mq,
    ]),
]) or throw new Error('failed to create DiscordClient');
$dc->init() or throw new Error('failed to initialize DiscordClient');
$loop->addSignal(SIGINT, function () use ($loop, $log) {
    $log->info('SIGINT received, exiting...');
    $loop->stop();
});
$loop->addSignal(SIGTERM, function () use ($loop, $log) {
    $log->info('SIGTERM received, exiting...');
    $loop->stop();
});
