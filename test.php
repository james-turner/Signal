<?php

declare(ticks=1);

require_once "bootstrap.php";

use Signal\Signal;
use IO\IO;
use Socket\Socket;
use Unicorn\Server;

// Options
$opts = array(
    'listeners' => array(
        'tcp://0.0.0.0:8081'
    ),
    'workers' => 5
);


$unicorn = new Unicorn\Server($opts);
$unicorn->on('fork', function($unicorn){
    $worker = true;

    // Make queue sigs for INT|QUIT|TERM exit fast.
    foreach($unicorn->QUEUE_SIGS as $sig){
        Signal::trap($sig, function()use($sig){
            IO::write(STDOUT, "Child trapped $sig\n");
            exit(0);
        });
    }
    // Special trap for QUIT
    Signal::trap(Signal::QUIT, function()use(&$worker){
        IO::write(STDOUT, "CLEAN SHUTDOWN OF CHILD.\n");
        $worker = null;
    });
    // Set CHLD to default.
    Signal::trap(Signal::CHLD, SIG_DFL);

    IO::write(STDOUT, "In a fork!\n");
    $ready = $listeners = $unicorn->listeners;

retry:
    do {
        try {
            $nr = 0;
            while($res = array_shift($ready)){
                $sock = new Socket($res);
                if($client = $sock->tryAccept()){
                    IO::write($client, "Hello world\n");
                    fclose($client);
                    $nr +=1;
                    IO::write(STDOUT, "Found a client, wrote, disconnected.\n");
                }
                if($nr < 0) break;
            }

            if($nr > 0){
                // equivalent to ruby redo
                IO::write(STDOUT, "Retrying accept for funz!\n");
                goto retry;
            } else {
                // use this to sit and wait on stuff!
                // This can go funny when  we receive a signal...
                (list($ret,,) = IO::select($listeners, null, null, 10)) and ($ready = $ret);
                IO::write(STDOUT, "Child is looping!\n");
            }

        } catch(Exception $e){
            IO::write(STDERR, "Child loop has a funny! {$e->getMessage()}\n");
        }
    } while($worker);
    IO::write(STDOUT, "Exiting the child normally!\n");
});

// Start...
$unicorn->start();

$unicorn->wait();




