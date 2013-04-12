<?php

namespace Worker;

use IO\IO;
use IO\Stream;
use IO\InterruptException;
use Signal\Signal;
use Process\Process;
use Process\Errno\ECHILD;
use Process\Errno\ESRCH;

class Supervisor {

    public $workers = array();
    private $signals = array(Signal::QUIT, Signal::TERM, Signal::INT, Signal::TTIN, Signal::TTOU);
    private $queuedSignals = array();
    private $selfPipe;
    private $timeout = 15;
    private $logger;
    private $workerProcesses;
    private $opts = array(
        'logger' => '',
        'workers' => 3,
    );
    private $worker = null;

    public function __construct($opts = array()){
        if(!array_key_exists('logger', $opts)){
            $this->logger = new StreamLogger(STDERR , StreamLogger::DEBUG);
        }
        $this->opts = array_merge($this->opts, $opts);
        $this->workerProcesses = $this->opts['workers'];
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
        // Unblock all the signals we want to handle!
        Signal::unblock($this->signals+array(Signal::CHLD));
        // Trap signals.
        $self =& $this;
        foreach($this->signals as $signal){
            Signal::trap($signal, function()use($self, $signal){
                $self->queueSignal($signal);
                $self->awakenMaster();
            });
        }
        // Child sig is a special trap, we don't want to queue this signal for handling.
        Signal::trap(Signal::CHLD, function()use($self){ $self->awakenMaster(); });
    }

    /**
     * @param array|\Closure $worker
     */
    public function run($worker){
        if(is_callable($worker)){
            $this->worker = $worker;
        }
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
        $this->logger->info('master process ready');
        do {
            $this->reapAllWorkers();
            switch($this->dequeueSignal()){
                case null:
                    // no signals, sleep for a bit
                    $this->maintainWorkerCount();
                    $this->masterSleep($this->timeout);
                    break;
                case Signal::INT:
                case Signal::TERM:
                    // terminate fast!
                    $this->stop(false);
                    // drop out of loop
                    break 2;
                case Signal::TTIN:
                    // increment worker count
                    $this->workerProcesses++;
                    break;
                case Signal::TTOU:
                    // decrement worker count
                    $this->workerProcesses--;
                    break;
                case Signal::QUIT:
                    // drop out of loop and let normal shutdown proceed.
                    break 2;
            }
        }while(true);
        // graceful shutdown.
        $this->stop();
        $this->logger->info('master complete');
    }

    /**
     * Maintain the correct number of workers
     */
    private function maintainWorkerCount(){
        if(!$off = (count($this->workers) - $this->workerProcesses)){return;}
        if($off < 0){
            // too few
            $this->spawnMissingWorkers();
            return;
        }
        if($off > 0){
            // take workers with a higher worker number than the amount specified
            // and kill them off!
            for($i=1; $i<=$off; $i++){
                // count from the back of workers, deleting highest workers first.
                $workerNum = count($this->workers)-$i;
                if($pid = array_search($workerNum, $this->workers)){
                    $this->killWorker($pid, SIGQUIT);
                }
            }
        }
    }

    private function masterSleep($timeout){
        try {
            // Blocking select on pipe, waits until timeout or signal interrupt
            if((list($ret,,)=IO::select(array($this->selfPipe[0]), null, null, $timeout)) and empty($ret)){return;}
        } catch(InterruptException $e){ /* interrupts are good */ }

        // Read 11 bytes (arbitrary number) from the pipe,
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
     * ECHILD can be triggered
     * by no children pids
     */
    private function reapAllWorkers(){
        do{
            try {
                list($pid,) = Process::wait(WNOHANG);
                if(!$pid){return;} // no exiting pids
                $nr = $this->removeWorker($pid);
                $this->logger->info("reaped worker={$nr}");
            } catch(ECHILD $e){
                /**
                 * System indicates either no children
                 * or some other weird failure on process
                 * waiting.
                 * Don't hang around, we're done here.
                 */
                break;
            }
        }while(true);
    }

    /**
     * Fire up all missing workers that
     * are not already fired up.
     * @note configured by opts[workers]
     * @throws \RuntimeException
     */
    private function spawnMissingWorkers(){
        $workerNumber = -1;
        $worker = &$this->worker;
        if(null === $worker) {
            $this->logger->crit("invalid worker");
            throw new \RuntimeException("Invalid worker.");
        }
        while(($workerNumber+=1) < $this->workerProcesses){
            // if worker with this worker number is already out there,
            // then omit this worker number and carry on!
            if(in_array($workerNumber, $this->workers)){continue;}

            $pid = Process::fork(function()use($worker){
                // Unblock SIGQUIT from the default PHP blockage.
                Signal::unblock(array(SIGQUIT));
                // Invoke worker function
                call_user_func($worker);
                // Always exit safely - can't risk more than 1 supervisor!
                exit(0);
            });
            $this->logger->info("worker=$workerNumber ready");
            $this->workers[$pid] = $workerNumber;
        }
    }

    /**
     * Stop the supervisor in graceful
     * or non-graceful mode.
     * @param bool $graceful
     */
    public function stop($graceful = true){
        while(count($this->workers)){
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
        $pids = array_keys($this->workers);
        foreach($pids as $pid){
            $this->killWorker($pid, $signal);
        }
    }

    /**
     * Kill the worker by pid with signal
     * @param $pid
     * @param $signal
     */
    private function killWorker($pid, $signal){
        try {
            Process::kill($pid, $signal);
        } catch(ESRCH $e){
            $this->removeWorker($pid);
        }
    }

    /**
     * Remove worker indicated by associated
     * pid.
     * @param int $pid
     * @return int|null worker number
     */
    private function removeWorker($pid){
        $workerNumber = $this->workers[$pid];
        unset($this->workers[$pid]);
        return $workerNumber;
    }
}