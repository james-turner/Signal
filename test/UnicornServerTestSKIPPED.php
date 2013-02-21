<?php

declare(ticks=1);

require_once realpath(__DIR__ ."/../bootstrap.php");
require_once "IOHelper.php";

use Unicorn\Server;
use Unicorn\StreamLogger;

class SignalTest extends PHPUnit_Framework_TestCase {

    /**
     * @test
     */
    public function startingUpWorkers(){

        $server = null;
        with_redirect_io(function($STDOUT)use(&$server){
            $opts = array(
                'workers' => 5,
                'listeners' => array('tcp://0.0.0.0:' . rand_port()),
                'logger' => new StreamLogger($STDOUT, StreamLogger::DEBUG),
            );
            $server = new Server($opts);
            $server->on('fork', function(){
                // Hang around for a bit...
                sleep(3);
                exit(0);
            });
            $server->start();
        });

        $pid = posix_getpid();
        $log = "test_stdout.{$pid}.log";
        wait_master_ready($log);

        // assert correct number of workers!
        wait_workers_ready($log, 5);


        $pids = $server->pids;
        var_dump($pids);

        // kill off the server and see that all processes are shutdown
        $server->stop(false);

        // Manually kill off the pids?
        foreach($pids as $pid){
            posix_kill($pid, SIGKILL);
        }
    }


    public function maintainingWorkerCount(){

        $opts = array(
            'workers' => 5,
            'listeners' => array('tcp://0.0.0.0:'.rand_port())
        );

        $server = new Server($opts);
        $server->on('fork', function(){
            while(true){
                sleep(5);
            }
        });
        $server->start();

        $pid = posix_getpid();
        $log = "test_stdout.{$pid}.log";
        wait_master_ready($log);

        wait_workers_ready($log, 5);

        $pids = $server->pids;

        // kill off the first child in our pids list.
        $killed = $pids[0];
        posix_kill($killed, SIGINT);
        // make sure the signal goes out.
        pcntl_signal_dispatch();

        exec("ps $killed", $lines);
        $this->assertTrue(count($lines) === 1);

        // check that our pid count is now back to 5
        $this->assertEquals(5, $server->pids);
        // And that the server no longer has the killed pid in it's list.
        $this->assertFalse(array_key_exists($killed, $server->pids));

        // expect log

        $server->stop();
    }


    public function testHotReload(){

        // fire up server
        // restart server
        // test hot proc load

    }

}
