<?php

declare(ticks=1);

require_once realpath(__DIR__ ."/../../../bootstrap.php");
require_once realpath(__DIR__ ."/../../IOHelper.php");

use Worker\Supervisor;

class SupervisorTest extends PHPUnit_Framework_TestCase {

    public $supervisor;
    private $roguePids = array();

    public function tearDown(){
        // Paranoid cleardown of children in case a test goes wrong!
        while(-1 !== ($pid = pcntl_wait($status))){
            echo "Terminating rogue child $pid\n";
        }
        foreach($this->roguePids as $pid){
            posix_kill($pid, SIGKILL);
        }
    }


    public function testMaintainingWorkerCount(){

        list($r, $w) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if($pid === 0){
            with_redirect_io(function()use(&$w){
                $foreman = new Supervisor();
                $foreman->start();
                fwrite($w, json_encode($foreman->pids));
                $foreman->wait();
            });
            exit(0);
        }

        $pid = posix_getpid();
        // let master come up.
        sleep(1);
        stream_set_blocking($r, 0);
        $pids = json_decode(fread($r, 1024));
        stream_set_blocking($r, 1);
        // kill off the last child
        posix_kill(end($pids), SIGKILL);
        // give it a second to fire up another process.
        sleep(1);

        posix_kill($pid, SIGQUIT);
        // verify logs say that a worker died unexpectedly
        // and that logs say that another worker was started up.
    }


    public function testHotReload(){

        // fire up server
        // restart server
        // test hot proc load

    }


    public function testStoppingAllWorkers(){

        $foreman = new Supervisor();
        $foreman->start();
        $pids = $foreman->pids;

        foreach($pids as $pid){
            $this->assertTrue($this->pidExists($pid));
        }

        $foreman->stop(false);

        foreach($pids as $pid){
            $this->assertFalse($this->pidExists($pid));
        }

    }

    public function testSignalShutdown(){
        // before fork create streams
        list($read, $write) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $foremanPid = pcntl_fork();
        if($foremanPid === 0){
            // close read pipe.
            $foreman = new Supervisor();
            $foreman->start();
            fwrite($write, json_encode($foreman->pids));
            // should block infinitely...
            $foreman->wait();
            // child
            exit(0);
        } else {
            // blocks until something appears on stream
            $encoded = "";
            sleep(1);
            // non-blocking read so we can get everything off.
            stream_set_blocking($read, 0);
            do {
                $chunk = fread($read, 1024);
                $encoded .= $chunk;
            } while((strlen($chunk) > 0));
            stream_set_blocking($read, 1);

            // prime rogue pids for collection during teardown
            $this->roguePids = $pids = json_decode($encoded);

            // Check pids are up.
            foreach($pids as $pid){
                $this->assertTrue($this->pidExists($pid));
            }

            // Terminate!
            posix_kill($foremanPid, SIGTERM);
            pcntl_signal_dispatch();
            // Wait for parent to exit.
            pcntl_waitpid($foremanPid, $status);

            // Check child pids are dead too.
            foreach($pids as $pid){
                $this->assertFalse($this->pidExists($pid));
            }
        }

    }

    /**
     * Simple helper to assist in testing whether a pid
     * is currently a running process.
     * @param $pid
     * @return bool
     */
    private function pidExists($pid){
        exec("ps $pid", $lines, $return);
        return count($lines) >= 2;
    }

}


