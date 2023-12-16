<?php

namespace RPurinton;

Composer::autoLoad(__DIR__) or throw new \Exception("composer failed to autoload");

class Composer
{
    static function which_composer(string $wdir): string
    {
        if (file_exists("$wdir/composer.phar")) return "$wdir/composer.phar";
        if (file_exists("$wdir/composer")) return "$wdir/composer";
        $composer = trim(shell_exec('which composer') ?? '');
        if ($composer !== '') return $composer;
        $composer = trim(shell_exec('which composer.phar') ?? '');
        if ($composer !== '') return $composer;
        echo ("\n[INFO] composer not found, attempting to download...");
        return self::download_composer($wdir);
    }

    static function download_composer(string $wdir): string
    {
        $wdire = escapeshellarg($wdir);
        exec("cd $wdire && curl -sS https://getcomposer.org/installer | php 2>&1", $output, $exit_code);
        if ($exit_code !== 0) throw new \Exception("Composer download failed: " . implode("\n", $output));
        if (!file_exists("$wdir/composer.phar")) throw new \Exception("404 composer.phar not found");
        return "$wdir/composer.phar";
    }

    static function command(string $wdir, string $command): bool
    {
        $composer = self::which_composer($wdir);
        $wdire = escapeshellarg($wdir);
        exec("cd $wdire && export COMPOSER_ALLOW_SUPERUSER=1 && export COMPOSER_NO_INTERACTION=1 && $composer $command 2>&1", $output, $exit_code);
        if ($exit_code !== 0) {
            throw new \Exception(implode("\n", $output));
        }
        return self::check_autoload_exists($wdir);
    }

    static function check_autoload_exists(string $wdir): bool
    {
        return file_exists("$wdir/vendor/autoload.php");
    }


    public static function requireFile(string $path)
    {
        if (!file_exists($path)) throw new \Exception("required $path not found");
        require_once($path);
    }

    public static function autoLoad(string $wdir): bool
    {
        if (!file_exists("$wdir/vendor/autoload.php")) {
            echo ("[INFO] composer autoload not found, attempting to install...");
            self::command($wdir, 'install') or throw new \Exception("composer install failed");
            echo ("done.\n");
        }
        self::requireFile(__DIR__ . '/vendor/autoload.php');
        return true;
    }
}
