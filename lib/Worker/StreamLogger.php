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

    private $level = self::ERR;

    public function __construct($stream, $level = self::ERR){
        $this->stream = $stream;
        $this->level = $level;
    }

    public function __call($name, $args){
        $const = constant(__CLASS__."::".strtoupper($name));
        if(null === $const){
            throw new \InvalidArgumentException("Invalid log level '$name'.");
        }
        $this->log(array_shift($args), $const);
    }

    private function log($msg, $level){
        if($level <= $this->level){
            $format = "%s [%s] %s\n";
            $t = microtime(true);
            $micro = sprintf("%06d",($t - floor($t)) * 1000000);
            $d = new \DateTime( date('Y-m-d H:i:s.'.$micro,$t) );
            $date = $d->format("Y-m-d H:i:s.u");
            fwrite($this->stream, sprintf($format, $date, $this->levels[$level], $msg));
        }
    }

}