<?php

namespace RPurinton\GeminiDiscord;

class Commands
{
    public static function get(): array
    {
        $path = __DIR__ . "/../commands/";
        if (!is_dir($path)) throw new Error("commands folder not found");
        $files = glob($path . '*.json');
        if (!$files) throw new Error("no commands found");
        $commands = [];
        foreach ($files as $file) {
            $command = json_decode(file_get_contents($file), true);
            if (!$command) throw new Error("failed to load command file: $file");
            $commands[] = $command;
        }
        return $commands;
    }
}
