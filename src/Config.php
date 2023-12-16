<?php

namespace RPurinton\gemini-discord;

class Config
{
    public static function get(string $file): mixed
    {
        $path = __DIR__ . "/../config/$file.json";
        if (!file_exists($path)) throw new Error("Config file not found: $file.json");
        $config = json_decode(file_get_contents($path), true);
        if (!$config) throw new Error("Failed to load config file: $file.json");
        return $config;
    }
}
