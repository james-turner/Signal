<?php

namespace Worker;

class StreamLogger {

    private $stream;

    // BSD syslog protocol
    const EMERG   = 0;  // Emergency: system is unusable
    const ALERT   = 1;  // Alert: action must be taken immediately
    const CRIT    = 2;  // Critical: critical conditions
    const ERR     = 3;  // Error: error conditions
    const WARN    = 4;  // Warning: warning conditions
    const NOTICE  = 5;  // Notice: normal but significant condition
    const INFO    = 6;  // Informational: informational messages
    const DEBUG   = 7;  // Debug: debug messages

    private $levels = array(
        'emerg',
        'alert',
        'crit',
        'err',
        'warn',
        'notice',
        'info',
        'debug'
    );

    private $level = 0;

    public function __construct($stream, $level = self::ERR){
        $this->stream = $stream;
        $this->level = $level;
    }

    public function __call($name, $args){
        $const = constant(__CLASS__."::".strtoupper($name));
        if(null === $const){
            throw new \RuntimeException("invalid log level");
        }
        $this->log(array_shift($args), $const);
    }

    private function log($msg, $level){
        if($level <= $this->level){
            $format = "%s [%s] %s\n";
            $date = @date("Y-m-d H:i:s", time()); // using @ to avoid errors when date_default_timezone_set not having been done!
            fwrite($this->stream, sprintf($format, $date, $this->levels[$level], $msg));
        }
    }

}