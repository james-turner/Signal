<?php

declare(ticks=1);

require_once realpath(__DIR__ ."/../../../bootstrap.php");
require_once realpath(__DIR__ ."/../../IOHelper.php");

use Worker\Supervisor;

class SupervisorTest extends PHPUnit_Framework_TestCase {

    private $worker;

    public function setUp(){

        // Dummy worker for testing.
        $this->worker = function(){
            $seconds = 60;
            while($seconds-- > 0){
                echo "hello\n";
                sleep(1);
            }
        };
    }

    public function tearDown(){
        /**
         * @note this was the cause of the testNormalShutdown
         * and testFastDeath not passing while testStoppingAllWorkers
         * was running in the same test run. Signal handlers were
         * messing with the forked process!
         */
        // Clear back the sign handlers to their originals.
        foreach(array(SIGQUIT, SIGTERM, SIGINT, SIGTTIN, SIGTTOU, SIGCHLD) as $signal){
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
            $worker = $this->worker;
            with_redirect_io(function()use(&$w, $worker){
                $foreman = new Supervisor();
                $foreman->run($worker);
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

        $this->assertShutdown($pid, $log);

    }

    public function testIncrementingWorkersOnTheFly(){

        $pid = pcntl_fork();
        if($pid === 0){
            $worker = $this->worker;
            with_redirect_io(function()use(&$w, $worker){
                $foreman = new Supervisor();
                $foreman->run($worker);
                $foreman->start();
                $foreman->wait();
            });
            exit(0);
        }

        $log = "test_stderr.{$pid}.log";
        wait_workers_ready($log, 3);
        wait_master_ready($log);

        posix_kill($pid, SIGTTIN);

        // expect another work to become ready!
        $failed = true;
        try {
            wait_workers_ready($log, 4);
            $failed = false;
        } catch(Exception $e){}
        // term the supervisor
        posix_kill($pid, SIGTERM);

        $this->assertShutdown($pid, $log);

        if($failed) $this->fail("Could not instantiate extra worker");

    }

    public function testDecrementingWorkersOnTheFly(){

        $pid = pcntl_fork();
        if($pid === 0){
            $worker = $this->worker;
            with_redirect_io(function()use(&$w, $worker){
                $foreman = new Supervisor();
                $foreman->run($worker);
                $foreman->start();
                $foreman->wait();
            });
            exit(0);
        }

        $log = "test_stderr.{$pid}.log";
        wait_workers_ready($log, 3);
        wait_master_ready($log);

        posix_kill($pid, SIGTTOU);

        // expect another work to become ready!
        try {
            $stopped = false;
            $tries = 10;
            while($tries-- > 0){
                // should kill off a worker
                if(1===preg_match_all("/reaped worker=\\d+/m", fread(fopen($log,'r'),8092), $matches)){
                    $stopped = true;
                    break;
                }
                usleep(200000);
            }
        } catch(Exception $e){}
        // term the supervisor
        posix_kill($pid, SIGTERM);

        $this->assertShutdown($pid, $log);

        if(!$stopped) $this->fail("Could not reap extra worker");

    }

    public function testVariableNumberOfWorkers(){

        $this->truncateLog();

        $nrWorkers = 9;
        $foreman = new Supervisor(array('workers' => $nrWorkers));
        $foreman->run($this->worker);
        with_redirect_io(function()use($foreman){
            $foreman->start();
        });

        $pid = posix_getpid();
        $log = "test_stderr.{$pid}.log";
        wait_workers_ready($log, $nrWorkers);

        with_redirect_io(function()use($foreman){
            $foreman->stop();
        });

    }

    public function testHotReload(){

        $this->markTestIncomplete("Implement me!");
        // fire up server
        // restart server
        // test hot proc load
        // expect master re-exec?
        // expect winched message


    }


    public function testStoppingAllWorkers(){

        $this->truncateLog();

        $foreman = new Supervisor();
        $foreman->run($this->worker);
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
            $worker = $this->worker;
            with_redirect_io(function()use(&$w, $worker){
                $foreman = new Supervisor();
                $foreman->run($worker);
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
            // blocks until ready pipe has been written to.
            $num = stream_select($rs = array($r), &$ws, &$ex, 10);
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
            $this->assertShutdown($foremanPid, $log);

        }

    }

    public function testFastDeath(){

        list($r, $w) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        // Fork foreman...
        $foremanPid = pcntl_fork();
        if($foremanPid === 0){
            // fire up the supervisor!
            $worker = $this->worker;
            with_redirect_io(function()use(&$w, $worker){
                $foreman = new Supervisor();
                $foreman->run($worker);
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

            $this->assertShutdown($foremanPid, $log);

        }

    }

    public function testPauseSupervisor(){
        $this->markTestSkipped("Implementation missing.");
        // issue CTRL^Z in foreground process and it should pause, and all children should pause too?
    }


    public function testBadWorker(){

        $this->truncateLog();

        $this->setExpectedException('RuntimeException', 'Invalid worker.');

        with_redirect_io(function(){
            $foreman = new Supervisor();
            $foreman->start();
        });

    }


    /**
     * Wait for this PID to exit.
     * Throw exception is it fails within
     * 100 attempts.
     * @param $pid
     * @throws Exception
     */
    private function waitPidExit($pid){
        $tries = 200;
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
        throw new RuntimeException("Pid {$pid} never exited!");
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
        // reap the child because we don't want a zombie apocalypse
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

    private function assertShutdown($pid, $log){
        try {
            $this->waitPidExit($pid);
        }catch (Exception $e){
            $this->killShutdown($pid);
            $this->fail("Failed to terminate (parent and children): " . $e->getMessage());
        }

        $this->assertMasterComplete($log);
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

    /**
     *
     */
    private function truncateLog(){
        // Truncate the current log file if it exists to
        // avoid clashes in 2 tests running in local pid space.
        $log = 'test_stderr.'.posix_getpid().".log";
        file_exists($log) and ftruncate(fopen($log, 'w+b'), 0);
    }

}


