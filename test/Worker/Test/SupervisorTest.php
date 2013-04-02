<?php

declare(ticks=1);

require_once realpath(__DIR__ ."/../../../bootstrap.php");
require_once realpath(__DIR__ ."/../../IOHelper.php");

use Worker\Supervisor;

class SupervisorTest extends PHPUnit_Framework_TestCase {

    public $supervisor;

    public function tearDown(){
        /**
         * @note this was the cause of the testNormalShutdown
         * and testFastDeath not passing while testStoppingAllWorkers
         * was running in the same test run. Signal handlers were
         * messing with the forked process!
         */
        // Clear back the sign handlers to their originals.
        foreach(array(SIGQUIT, SIGTERM, SIGINT, SIGCHLD) as $signal){
            pcntl_signal($signal, SIG_DFL);
        }
    }


    /**
     * This test should ascertain that when a worker is
     * killed off, it is replaced correctly by another worker.
     */
    public function testMaintainingWorkerCount(){

        list($r, $w) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if($pid === 0){
            with_redirect_io(function()use(&$w){
                $foreman = new Supervisor();
                $foreman->start();
                fwrite($w, json_encode($foreman->workers));
                fclose($w);
                $foreman->wait();
            });
            exit(0);
        }

        // let master come up.
        $log = "test_stderr.{$pid}.log";
        wait_master_ready($log);
        wait_workers_ready($log, 3);

        // wait for write on stream!
        $num = stream_select($rs = array($r), $ws = array(), $ex = array(), 15);
        $this->assertEquals(1, $num);

        $workers = json_decode(fread($rs[0], 1024), true);
        fclose($r); // close the resource after we're done with reading!
        $pids = array_keys($workers);
        $workerPid = end($pids); // use the last worker pid

        // kill off the last child
        $this->assertTrue(posix_kill($workerPid, SIGKILL));

        // wait for reaped pid to appear in log
        $reaped = false;
        $tries = 10;
        while($tries-- > 0){
            if(preg_match("/reaped worker=\\d+/", fread(fopen($log,'r'), 8092))){
                $reaped = true;
                break;
            }
            usleep(200000);
        }
        $this->assertTrue($reaped, "Failed to reap any workers");

        // check worker comes back up!
        try {
            $newStarter = false;
            wait_workers_ready($log, 4);
            $newStarter = true;
        } catch(Exception $e){ /* unable to ascertain the correct number of workers */ }
        $this->assertTrue($newStarter, "Worker {$workers[$workerPid]} failed to come back up.");

        // Kill the master
        posix_kill($pid, SIGQUIT);

        try {
            // Wait for forked process to exit successfully.
            $this->waitPidExit($pid);
        }catch(Exception $e){
            // Failure to exit forces a shutdown.
            $this->killShutdown($pid);
            $this->fail("Failed to shutdown master $pid");
        }

        // Check master shuts down
        $this->assertMasterComplete($log);

    }

    public function testVariableNumberOfWorkers(){

        $foreman = new Supervisor(array('workers' => 6));
        with_redirect_io(function()use($foreman){
            $foreman->start();
        });

        $pid = posix_getpid();
        $log = "test_stderr.{$pid}.log";
        wait_workers_ready($log, 6);

        with_redirect_io(function()use($foreman){
            $foreman->stop();
        });

    }

    public function testHotReload(){

        // fire up server
        // restart server
        // test hot proc load
        // expect master re-exec?
        // expect winched message


    }


    public function testStoppingAllWorkers(){

//        throw new PHPUnit_Framework_IncompleteTestError();

        $foreman = new Supervisor();
        with_redirect_io(function()use($foreman){
            $foreman->start();
        });

        $pid = posix_getpid();
        $master_log = "test_stderr.{$pid}.log";

        wait_workers_ready($master_log, 3);

        with_redirect_io(function()use($foreman){
            // normal stop (not signal based)
            $foreman->stop(false);
        });

        // wait for each worker to stop
        $stopped = false;
        $tries = 10;
        while($tries-- > 0){
            if(3===preg_match_all("/reaped worker=\\d+/m", fread(fopen($master_log,'r'),8092), $matches)){
                $stopped = true;
                break;
            }
            usleep(200000);
        }

        $this->assertTrue($stopped);
    }

    public function testNormalShutdown(){

        list($r, $w) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        // Fork foreman...
        $foremanPid = pcntl_fork();
        if($foremanPid === 0){
            // fire up the supervisor!
            with_redirect_io(function()use(&$w){
                $foreman = new Supervisor();
                $foreman->start();
                // send ready pipe signal.
                fwrite($w, ".");
                // should block indefinitely...
                fclose($w);
                $foreman->wait();
            });
            // child
            exit(0);
        } else {

            // wait on ready pipe.
            $rs = array($r);
            $ws = $ex = array();
            // blocks until ready pipe has been written to.
            $num = stream_select($rs, $ws, $ex, 10);
            $this->assertEquals(1, count($num));

            // Wait for master/workers to be ready.
            $log = "test_stderr.{$foremanPid}.log";
            wait_master_ready($log);
            // Check that 3 workers became ready.
            wait_workers_ready($log, 3);

            // Terminate!
            $killed = posix_kill($foremanPid, SIGQUIT);
            $this->assertTrue($killed);

            // Wait for parent to exit.
            try {
                $this->waitPidExit($foremanPid);
            }catch (Exception $e){
                $this->killShutdown($foremanPid);
                $this->fail("Failed to terminate parent using SIGQUIT.");
            }

            $this->assertMasterComplete($log);

        }

    }

    public function testFastDeath(){

        list($r, $w) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        // Fork foreman...
        $foremanPid = pcntl_fork();
        if($foremanPid === 0){
            // fire up the supervisor!
            with_redirect_io(function()use(&$w){
                $foreman = new Supervisor();
                $foreman->start();
                // send ready pipe signal.
                fwrite($w, ".");
                // should block infinitely...
                fclose($w);
                $foreman->wait();
            });
            exit(0);
        } else {
            // wait on ready pipe.
            $rs = array($r);
            $ws = $ex = array();
            // blocks until ready pipe has been written to.
            $num = stream_select($rs, $ws, $ex, 10);
            $this->assertEquals(1, count($num));

            // Wait for master/workers to be ready.
            $log = "test_stderr.{$foremanPid}.log";
            wait_master_ready($log);
            // Check that 3 workers became ready.
            wait_workers_ready($log, 3);

            // Terminate - Quick kill!
            $killed = posix_kill($foremanPid, SIGTERM);
            $this->assertTrue($killed);

            try {
                $this->waitPidExit($foremanPid);
            }catch (Exception $e){
                $this->killShutdown($foremanPid);
                $this->fail("Failed to terminate (parent and children).");
            }

            $this->assertMasterComplete($log);

        }

    }

    /**
     * Wait for this PID to exit.
     * Throw exception is it fails within
     * 100 attempts.
     * @param $pid
     * @throws Exception
     */
    private function waitPidExit($pid){
        $tries = 100;
        while($tries-- > 0){
            $exited = pcntl_waitpid($pid, $status, WNOHANG);
            if($exited === 0){
                usleep(200000);
                continue;
            }
            if($exited === $pid){
                $this->assertEquals(0,pcntl_wexitstatus($status), "[$exited] Didn't exit normally.\n");
                return;
            }
        }
        throw new Exception("Pid {$pid} never exited!");
    }

    /**
     * Kill process group by pid.
     * If supplied with the worker pid
     * it will kill all children.
     * @param $pid
     */
    private function killShutdown($pid){

        $killed = posix_kill($pid, SIGKILL);
        $killed or print("Shutdown failure with message: " . posix_strerror(posix_get_last_error()));
        // assert killed
        $this->assertTrue($killed);
        pcntl_waitpid($pid, $status);
    }

    /**
     * Check that the master completes
     * it routine.
     * @param $log
     * @throws Exception
     */
    private function assertMasterComplete($log){
        $tries = 10;
        // check for message in log saying that "master complete"
        while($tries-- > 0){
            if(1===preg_match("/master complete/m", fread(fopen($log,'r'),8092))){
                return;
            }
            usleep(200000);
        }

        throw new Exception("Master complete failed");
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


