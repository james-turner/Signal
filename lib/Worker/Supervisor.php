<?php

namespace Worker;

use IO\IO;
use IO\Stream;
use Signal\Signal;
use Signal\InterruptException;
use Process\Process;
use Process\SystemCallException;

class Supervisor {

    public $pids = array();
    private $signals = array(Signal::QUIT, Signal::TERM, Signal::INT);
    private $queuedSignals = array();
    private $selfPipe;
    private $timeout = 15;
    private $logger;
    private $opts = array(
        'logger' => '',
    );

    public function Supervisor($opts = array()){
        $this->logger = new StreamLogger('' , StreamLogger::DEBUG);
    }

    public function start(){
        // initialise self piping for signals
        $this->initSelfPipe();

        // spawn any missing workers
        $this->spawnMissingWorkers();

        // Prime signals
        $this->initSignalHooks();
    }

    /**
     * Initialise the signal hooks
     * @note doing this after spawning forked workers
     * makes sure we don't accidentally trap signals
     * in our child workers.
     */
    private function initSignalHooks(){
        // Trap signals.
        $self =& $this;
        foreach($this->signals as $signal){
            Signal::trap($signal, function()use($self, $signal){
                $self->queueSignal($signal);
                $self->awakenMaster();
            });
        }
        Signal::trap(Signal::CHLD, function()use($self){ IO::write(STDOUT, "child operand.\n"); $self->awakenMaster(); });
    }

    /**
     * Set up the self pipe mechanism required
     * for signal interruption
     */
    private function initSelfPipe(){
        $this->selfPipe = IO::pipe();
    }

    /**
     * Put a signal on the signal queue.
     * @param int $signal
     */
    public function queueSignal($signal){
        $this->queuedSignals[] = $signal;
    }

    /**
     * Dequeue a signal
     * @return null|int
     */
    public function dequeueSignal(){
        return array_shift($this->queuedSignals);
    }

    /**
     * Wake the master up!
     * by lighting the PIPE
     */
    public function awakenMaster(){
        IO::write($this->selfPipe[1], ".");
    }

    /**
     *
     */
    public function wait(){
        do {
            $this->reapAllWorkers();
            switch($this->dequeueSignal()){
                case null:
                    // no signals, sleep for a bit
                    $this->masterSleep($this->timeout);
                    break;
                case Signal::INT:
                case Signal::TERM:
                    // terminate fast!
                    $this->stop(false);
                    // drop out of loop
                    break 2;
                case Signal::QUIT:
                    // drop out of loop and let normal shutdown proceed.
                    break 2;
            }
            IO::write(STDOUT, "NOT INT/TERM\n");
        }while(true);
        // graceful shutdown.
        $this->stop();
    }

    private function masterSleep($timeout){
        try {
            // Blocking select on pipe, waits until timeout or signal interrupt
            if((list($ret,,)=IO::select(array($this->selfPipe[0]), null, null, $timeout)) and $ret[0]){return;}
        } catch(InterruptException $e){ /* do nothing */ }

        // Read 11 bytes (arbitrary number wtf!) from the pipe,
        // should read off multiple tokens.
        $stream = new Stream($this->selfPipe[0],'r');
        $stream->tryRead(11);
    }

    /**
     * Reap all dead children
     * Loops until zero (0) is returned
     * from the Process::wait(), indicating
     * no processes are available for
     * reaping.
     */
    private function reapAllWorkers(){
        do{
            try {
                list($pid,$status) = Process::wait(WNOHANG);
                fwrite(STDOUT, "[$pid] exited with ".pcntl_wexitstatus($status). "\n");
                if(!$pid){return;} // nothing to report
                $this->removeWorker($pid);
            } catch(SystemCallException $e){
                /**
                 * System indicates either no children
                 * or some other weird failure on process
                 * waiting.
                 * Don't hang around, we're done here.
                 */
                fwrite(STDOUT, "syscall exception\n");
                break;
            }
        }while(true);
    }

    /**
     * Fire up all missing workers that
     * are not already fired up.
     * @note hardcoded to 3 at the minute!
     */
    private function spawnMissingWorkers(){
        for($i=0; $i<3; $i++){
            $pid = Process::fork(function(){
                while(true){
                    fwrite(STDOUT, "hello\n");
                    fflush(STDOUT);
                    sleep(1);
                }
                exit(0);
            });
            IO::write(STDOUT, "worker=$i ready");
            $this->pids[$pid] = $pid;
        }

    }

    /**
     * Stop the supervisor in graceful
     * or not graceful mode.
     * @param bool $graceful
     */
    public function stop($graceful = true){
        while(count($this->pids)){
            $pidCount = count($this->pids);
            fwrite(STDOUT, "Killing all workers [$pidCount]\n");
            $this->killEachWorker($graceful ? Signal::QUIT : Signal::TERM);
            usleep(100000);
            $this->reapAllWorkers();
        }
        $this->killEachWorker(Signal::KILL);
    }

    /**
     * Kill all workers with a particular
     * signal.
     * @param int $signal
     */
    private function killEachWorker($signal){
        foreach($this->pids as $pid){
            Process::kill($pid, $signal);
        }
    }

    /**
     * Remove worker indicated by associated
     * pid.
     * @param int $pid
     */
    private function removeWorker($pid){
        unset($this->pids[$pid]);
    }
}