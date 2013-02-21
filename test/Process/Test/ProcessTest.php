<?php

namespace Process\Test;

use Process\Process;

require_once realpath(__DIR__ ."/../../../bootstrap.php");

class ProcessTest extends \PHPUnit_Framework_TestCase {

    public function tearDown(){
        // Paranoid cleardown of children in case a test goes wrong!
        while(-1 !== ($pid = pcntl_wait($status))){
            echo "Terminating rogue child $pid\n";
        }
    }


    public function testForkPid(){

        $pid = Process::fork(function(){
            usleep(300000);
            // Exit is important otherwise the rest of
            // the test execution is done!
            exit(0);
        });

        $this->assertTrue($this->pidExists($pid));

        // Wait (blocking) for all children.
        Process::wait();

        // Once we've waited we should be able to assert that the pid has been torn down correctly.
        $this->assertFalse($this->pidExists($pid));
    }


    public function testBadWait(){

        // Waiting on non-existant children will throw a runtime exception.
        $this->setExpectedException('RuntimeException');
        Process::wait();

    }

    public function testExitStatus(){

        $childPid = Process::fork(function(){
            exit(54);
        });

        list($exitPid, $status) = Process::wait();

        $this->assertEquals($childPid, $exitPid);
        $this->assertEquals(54, $status);

    }

    public function testNonBlockingWait(){

        $pid = Process::fork(function(){
            sleep(10);
            exit(0);
        });

        list($exitPid,) = Process::wait(WNOHANG);

        $this->assertEquals(0, $exitPid);
        $this->assertTrue($this->pidExists($pid));

        Process::kill($pid, SIGKILL);
        Process::wait();

        $this->assertFalse($this->pidExists($pid));
    }

    public function testKill(){

        $pid = Process::fork(function(){
            sleep(10);
            exit(0);
        });

        Process::kill($pid, SIGKILL);

        list($exitPid,) = Process::wait();

        $this->assertEquals($pid, $exitPid);

    }

    public function testKillFailure(){

        $this->setExpectedException('RuntimeException');
        // Random pid number, although in 32bit systems this is the maximum pid, varies by system spec.
        // Unlikely to be this pid number!
        Process::kill(32768, SIGKILL);

    }

    public function testCurrentProcessId(){

        $this->assertEquals(posix_getpid(), Process::pid());

    }

    public function testForkedPid(){

        list($r, $w) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $ppid = posix_getpid();
        $pid = Process::fork(function()use($ppid, $r, $w){
            fclose($r);
            $pid = Process::pid();
            fwrite($w, serialize($pid));
            fclose($w);
            exit(0);
        });
        fclose($w);

        // Process should hang here until its dead!
        Process::wait();

        $reads = array($r);
        $writes = array();
        $excepts = array();
        // Blocks on streams until something appears on them.
        $res = stream_select($reads, $writes, $excepts, 1);

        $contents = stream_get_contents($reads[0]);
        $childPid = unserialize($contents);

        $this->assertNotEquals($ppid, $childPid);
        $this->assertEquals($pid, $childPid);
    }

    public function testParentPid(){

        posix_getppid();

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