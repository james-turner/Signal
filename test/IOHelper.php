<?php

use IO\IO;
use IO\STDOUT;
use IO\STDERR;

function with_redirect_io($do){

    $pid = posix_getpid();
    $stdoutFile = "test_stdout.{$pid}.log";
    $stderrFile = "test_stderr.{$pid}.log";
    STDOUT::reopen($stdoutFile,'a+');
    STDERR::reopen($stderrFile,'a+');

    // This will catch all errors and log them into whatever the STDERR is set to.
    set_error_handler(function($errno, $errstr, $errfile, $errline = null, $errcontext = null){
        if (0===error_reporting()) {
            // suppressed so don't report it!
            return;
        }
        fwrite(STDERR, "$errno, $errstr, $errfile, $errline".print_r($errcontext, true)."\n");
        // By returning  false we are falling back to the default php handler
        return false;
    });

    try {
        call_user_func($do);
    }catch(Exception $e){
        // throw later
    }
    // roepen files to their correct outputs.
    STDOUT::reopen(STDOUT);
    STDERR::reopen(STDERR);
    // restore previous error handler.
    restore_error_handler();

    register_shutdown_function(function()use($stdoutFile, $stderrFile){
        // unlink files
//        @unlink($stdoutFile);
//        @unlink($stderrFile);
    });

    // throw any errors
    if(isset($e)){
        throw $e;
    }
}

function wait_workers_ready($path, $number){
    $tries = 100;
    while($tries-- > 0){
        try {
            if(preg_match_all("/worker=\\d+ ready/m", IO::read($path), $matches) === $number){
                return;
            }
        } catch(\IO\NoEntityException $e){ /* file not available yet */ }
        usleep(200000);
    }
    throw new RuntimeException("worker processes never became ready");
}


function wait_master_ready($master_log){
    $tries = 10;
    while($tries-- > 0){
        try {
            if(1===preg_match("/master process ready/m", IO::read($master_log))){
                return;
            }
        } catch(\IO\NoEntityException $e){ /* file not available yet */ }
        usleep(200000);
    }
    throw new RuntimeException("master process never became ready");
}

function assert_shutdown($pid){
    posix_kill($pid, SIGQUIT);
    // waiting for death
    pcntl_waitpid($pid, $status);
    // assert pid exists

    PHPUnit_Framework_Assert::assertEquals(0, pcntl_wexitstatus($status));
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
