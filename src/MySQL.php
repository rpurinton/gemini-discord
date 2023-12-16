<?php

namespace RPurinton\GeminiDiscord;


class MySQL
{
    private $sql;

    public function __construct(private Log $log)
    {
    }

    public function connect(): bool
    {
        $this->log->debug('MySQL::connect');
        try {
            extract(Config::get('mysql')) or throw new Error('failed to extract mysql config');
            $this->sql = mysqli_connect($hostname, $username, $password, $database) or throw new Error('failed to connect to mysql');
            $this->log->debug('MySQL connected');
            return true;
        } catch (\mysqli_sql_exception $e) {
            throw new Error($e->getMessage(), $e->getCode(), $e);
        }
        return false;
    }

    private function connectIfNeeded(): bool
    {
        if (!$this->sql) return $this->connect();
        if (mysqli_ping($this->sql)) return true;
        return $this->connect();
    }

    public function query($query)
    {
        $result = null;
        try {
            $this->connectIfNeeded() or throw new Error('MySQL connect failed');
            $result = mysqli_query($this->sql, $query);
            if (!$result) throw new Error('MySQL query error: ' . mysqli_error($this->sql));
        } catch (\mysqli_sql_exception $e) {
            throw new Error($e->getMessage());
        } finally {
            return $result;
        }
    }

    public function count($result)
    {
        return mysqli_num_rows($result);
    }

    public function insert($query)
    {
        $this->query($query);
        return mysqli_insert_id($this->sql);
    }

    public function assoc($result)
    {
        return mysqli_fetch_assoc($result);
    }

    public function escape(mixed $text): mixed
    {
        if (is_null($text)) return null;
        if (is_array($text)) return array_map($this->escape(...), $text);
        return mysqli_real_escape_string($this->sql, $text);
    }

    public function single($query)
    {
        try {
            $result = $this->query($query);
        } catch (\mysqli_sql_exception $e) {
            throw new Error($e->getMessage());
        } finally {
            return mysqli_fetch_assoc($result);
        }
    }

    public function multi($query): array
    {
        $result = [];
        try {
            $this->connectIfNeeded() or throw new Error('MySQL connect failed');
            if (!mysqli_multi_query($this->sql, $query)) throw new Error('MySQL multi-query error: ' . mysqli_error($this->sql));
            do {
                if ($res = mysqli_store_result($this->sql)) {
                    $result[] = mysqli_fetch_all($res, MYSQLI_ASSOC);
                    mysqli_free_result($res);
                }
            } while (mysqli_more_results($this->sql) && mysqli_next_result($this->sql));
        } catch (\mysqli_sql_exception $e) {
            throw new Error($e->getMessage());
        } finally {
            return $result;
        }
    }

    public function insert_id()
    {
        return mysqli_insert_id($this->sql);
    }

    public function disconnect()
    {
        $this->log->debug('MySQL::disconnect');
        mysqli_close($this->sql);
    }
}
