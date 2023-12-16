<?php

namespace RPurinton\gemini-discord;

class Error extends \Exception implements \Throwable
{
    public function __construct(protected $message, protected $code = 0, protected ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
