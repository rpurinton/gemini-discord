<?php

namespace RPurinton\gemini-discord;

class Locales
{
    public static function get(): array
    {
        $path = __DIR__ . "/../locales/";
        if (!is_dir($path)) throw new Error("locales folder not found");
        $files = glob($path . '*.json');
        if (!$files) throw new Error("no locales found");
        $locales = [];
        foreach ($files as $file) {
            $locale = json_decode(file_get_contents($file), true);
            if (!$locale) throw new Error("failed to load locale file: $file");
            $locales[basename($file, '.json')] = $locale;
        }
        return $locales;
    }
}
