<?php


namespace Signal\Test;

require_once realpath(__DIR__ ."/../../../bootstrap.php");

use Signal\Signal;

class SignalTest extends \PHPUnit_Framework_TestCase {

    /**
     * Always clear down the SIGUSR2 and reset
     * it to it's default status to avoid problems in
     * the test run where SIGUSR2 might actually get
     * sent to the running instance of phpunit test
     * suite.
     */
    public function tearDown(){
        pcntl_signal(SIGUSR2, SIG_DFL);
    }

    public function testSignalTrapping(){

        Signal::trap(SIGUSR2, function()use(&$result){
            $result = true;
        });

        $this->triggerAndDispatch(SIGUSR2);

        $this->assertTrue($result);

    }

    public function testSignalHandlerNotRun(){

        $run = false;
        Signal::trap(SIGUSR2, function()use(&$run){
            $run = true;
        });

        // NO SIGNAL SENT SO NOT EXPECTED TO RUN.
        pcntl_signal_dispatch();

        $this->assertFalse($run);

    }

    public function testIgnoringSignals(){

        $run = false;
        Signal::trap(SIGUSR2, function()use(&$run){
            $run = true;
        });
        // Explicity ignore this signal after it having been set.
        Signal::trap(SIGUSR2, null);

        $this->triggerAndDispatch(SIGUSR2);

        $this->assertFalse($run);

    }

    /**
     * @note Test skipped to due nature of the test run.
     */
    public function testResettingHandler(){

        $this->markTestSkipped('This test is skipped to the fact that issuing a SIGUSR2 to phpunit will kill it off.');

        $run = false;
        Signal::trap(SIGUSR2, function()use(&$run){
            $run = true;
        });

        // Actually doing this causes the signal to kill the test...
        Signal::trap(SIGUSR2, SIG_DFL);

        $this->triggerAndDispatch(SIGUSR2);

        $this->assertFalse($run);

    }

    public function testSignalHandlerWithStringSignals(){

        $run = false;
        Signal::trap("USR2", function()use(&$run){
            $run = true;
        });

        $this->triggerAndDispatch(SIGUSR2);

        $this->assertTrue($run);

    }

    public function testSignalHandlerWithClassConsts(){

        $run = false;
        Signal::trap(Signal::USR2, function()use(&$run){
            $run = true;
        });

        $this->triggerAndDispatch(SIGUSR2);

        $this->assertTrue($run);

    }


    public function testMultiSignalTrapping(){

        $i = 0;
        Signal::trap(SIGUSR2, function()use(&$i){
            $i++;
        });

        posix_kill(posix_getpid(), SIGUSR2);
        posix_kill(posix_getpid(), SIGUSR2);
        posix_kill(posix_getpid(), SIGUSR2);
        posix_kill(posix_getpid(), SIGUSR2);

        pcntl_signal_dispatch();

        $this->assertEquals(4, $i);

    }


    public function testUnknownSignal(){

        $this->setExpectedException('Signal\UnknownSignal');

        Signal::trap("SIGSOMETHING", null);

    }

    /**
     * @dataProvider minAndMaxBounds
     */
    public function testSignalsOutsideRange($bound){

        $this->setExpectedException('RuntimeException');

        Signal::trap($bound, SIG_DFL);

    }

    public function testSignalsInsideRange(){

        // throw no exceptions
        Signal::trap(31, SIG_DFL);

        Signal::trap(1, SIG_DFL);

    }

    public function testSignalDispatchSelf(){
        $run = false;
        Signal::trap(SIGUSR2, function()use(&$run){
            $run = true;
        });

        $sign = new Signal(SIGUSR2);

        $sign->dispatch();

        $this->assertTrue($run);

    }

    public function testSignalDispatchExternal(){

        $pid = pcntl_fork();
        if($pid === 0){
            // child!
            fclose(STDOUT);
            fclose(STDERR);
            sleep(1);
            exit(0);
        }

        // Trap the signal in the parent and make the test fail if we receive the signal here.
        Signal::trap("USR2", function(){
            throw new \RuntimeException("Test failed!");
        });

        $sign = new Signal("USR2");

        $sign->dispatch($pid);

        // reap dead child correctly before checking non-existence.
        pcntl_wait($status);

        // check child no longer exists!
        exec("ps $pid", $lines);
        $this->assertFalse(count($lines) >= 2);

    }

    /**
     * Data provider for minimum and maximum boundaries
     * @return array
     */
    public function minAndMaxBounds(){
        return array(
            array(0),
            array(32),
        );
    }

    /**
     * Helper to package up the
     * @param $signal
     */
    private function triggerAndDispatch($signal){

        // this is posix system safe so don't expect much from Windows!
        posix_kill(posix_getpid(), $signal);
        // Weirdly (or not i haven't decided yet) this blocks all further processing...
        pcntl_signal_dispatch();

    }
}