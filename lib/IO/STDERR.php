<?php

namespace IO;

class STDERR extends STDOUT {

    const RAW = STDERR;
    static public $registered;
    static private $errHandler;

    /**
     * @override
     * {@inheritDoc}
     */
    static public function reopen($file_or_stream, $mode = null){
        parent::reopen($file_or_stream, $mode);
        if(static::$errHandler){
            restore_error_handler();
            static::$errHandler = null;
        }
        if($file_or_stream !== static::RAW){
            // capturing trigger_error messages
            static::$errHandler = set_error_handler(function($errno, $errstr, $errfile, $errline, $errcontext){
                if(0 === error_reporting()) return; // exit on suppressed errors.
                fwrite(STDERR::RAW, "{$errstr}\n");
            });
        }
    }
}

stream_filter_register("IO\\STDERR", "IO\\STDERR");