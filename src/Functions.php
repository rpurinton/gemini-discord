<?php

namespace RPurinton\GeminiDiscord;

class Functions
{
    public static function get(): array
    {
        $path = __DIR__ . "/../functions/";
        if (!is_dir($path)) throw new \Error("functions folder not found");
        $function_definitions = [];
        $function_handlers = [];

        $subfolders = array_filter(scandir($path), function ($folder) use ($path) {
            return is_dir($path . $folder) && $folder != "." && $folder != "..";
        });

        foreach ($subfolders as $subfolder) {
            $define_path = $path . $subfolder . "/define.json";
            $handle_path = $path . $subfolder . "/handle.php";

            if (file_exists($define_path)) {
                $define_content = file_get_contents($define_path);
                $define_array = json_decode($define_content, true);
                // TODO: Validate the define.json file matches OpenAPI standard

                if (file_exists($handle_path)) {
                    ob_start();
                    include $handle_path;
                    $output = ob_get_clean();

                    if (empty($output)) {
                        // TODO: Validate that the message handler function has been set
                        $function_definitions[] = $define_array;
                        $function_handlers[] = $handle_path;
                    }
                }
            }
        }

        return [
            'function_definitions' => $function_definitions,
            'function_handlers' => $function_handlers
        ];
    }
}
