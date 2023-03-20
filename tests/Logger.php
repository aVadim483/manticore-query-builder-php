<?php

class Logger extends Psr\Log\AbstractLogger
{
    public array $data = [];
    public function log($level, $message, array $context = array())
    {
        $this->data[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }

    public function reset()
    {
        $this->data = [];
    }
}
