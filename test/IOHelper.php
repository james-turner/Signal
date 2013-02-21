<?php

use IO\IO;

function with_redirect_io($do){

    $pid = posix_getpid();
    $stdoutFile = "test_stdout.{$pid}.log";
    $stderrFile = "test_stderr.{$pid}.log";
    $STDOUT = fopen($stdoutFile,'a');
    $STDERR = fopen($stderrFile,'a');

    // This will catch all errors and log them into whatever the STDERR is set to.
    set_error_handler(function($errno, $errstr, $errfile, $errline = null, $errcontext = null)use(&$STDERR){
        fwrite($STDERR, "$errno, $errstr, $errfile, $errline");
    });

    try {
        ob_start();
        call_user_func($do, $STDOUT, $STDERR);
        fwrite($STDOUT, ob_get_contents());
        ob_end_clean();
    }catch(Exception $e){
        // throw later
    }

    fclose($STDOUT);
    fclose($STDERR);

    restore_error_handler();

    if(isset($e)){
        throw $e;
    }

    register_shutdown_function(function()use($stdoutFile, $stderrFile){
        // unlink files
        unlink($stdoutFile);
        unlink($stderrFile);
    });

}

function wait_workers_ready($path, $number){
    $tries = 10;
    while($tries-- > 0){
        if(preg_match_all("/worker=\\d+ ready/m", IO::read($path), $matches) === $number){
            var_dump($matches);
            return;
        }
        usleep(200000);
    }
    throw new RuntimeException("worker processes never became ready");
}


function wait_master_ready($master_log){
    $tries = 10;
    while($tries-- > 0){
        if(1===preg_match("/master process ready/m", IO::read($master_log))){
            return;
        }
        usleep(200000);
    }
    throw new RuntimeException("master process never became ready");
}


function rand_port($tries = -1){
    $port = 0;
    while($tries-- >= 0){
        $port = rand(8001, 8999);
        if(false === ($s = stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $errstr))){
            fclose($s);
            continue;
        }
        fclose($s);
        break;
    }
    return $port;
}
