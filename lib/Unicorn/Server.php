<?php

namespace Unicorn;

use IO\IO;
use Socket\Socket;
use Signal\Signal;
use Process\Process;
use IO\IOStream;

class Server extends \Socket\Server {

    // begin parent stuff!!!!
    public $SIGS_QUEUE = array();
    public $SELF_PIPE = null;
    public $QUEUE_SIGS = array(Signal::INT, Signal::QUIT, Signal::TERM);

    public $pids = array();
    public $events = array();

    private $opts = array(
        'listeners' => array('tcp://0.0.0.0:8081'),
        'workers' => 2
    );

    public $listeners = array();

    public function __construct($opts = array()){
        $this->opts = array_merge($this->opts, $opts);

    }

    public function start(){
        $this->initSelfPipe();

        $self =& $this;

        $SIGS_QUEUE =& $this->SIGS_QUEUE;
        foreach($this->QUEUE_SIGS as $sig){
            Signal::trap($sig, function()use($sig, &$SIGS_QUEUE, $self){
                IO::write(STDOUT, "CAUGHT A SIG $sig\n");
                $SIGS_QUEUE[] = $sig;
                $self->awakenMaster();
            });
        }
        // Trap child signals, but don't add them to the signal queue.
        Signal::trap(Signal::CHLD, function()use($self){ $self->awakenMaster(); });

        $this->initListeners();
        // Spawn workers AFTER trapping signals... otherwise we're doomed!
        $this->spawnMissingWorkers();
    }

    public function initListeners(){
        $this->listeners = array();
        foreach($this->opts['listeners'] as $addr){
            $this->listeners[] = $this->listen($addr);
        }
    }

    public function on($event, $block){
        $this->events[$event] = $block;
    }

    public function initSelfPipe(){
        $this->SELF_PIPE = Socket::pair();
    }

    public function spawnMissingWorkers(){
        $self =& $this;
        for($i=0; $i< $this->opts['workers']; $i++){
            if($pid = Process::fork(function()use($self){
                // DURING THIS FORK WE PROBABLY WANT TO CLEAR DOWN
                // THIS CLASS!!!!
                $self->initSelfPipe();
                $self->pids = array();
                $self->SIGS_QUEUE = array();
//                fclose($self->SELF_PIPE[0]); // close pipes and reinit them?
                if($event = @$self->events['fork']){
                    try {
                        $event($self);
                    } catch(\Exception $e){
                        IO::write(STDOUT, "Fork process shat itself!\n");
                    }
                    // always exit here... forks should exit!
                }
                exit;
            })){
                $this->pids[$pid] = $pid;
            }
        }
    }

    /**
     * Wait for our workers to complete their jobs, handle signals received
     * and tidy up workers that have been shutdown.
     */
    public function wait(){
        do {
            try {
                $this->reapAllWorkers();
                switch(array_shift($this->SIGS_QUEUE)){
                    case null:
                        // nothing left on the stack!
                        $this->masterSleep(15);
                        break;
                    case Signal::INT:
                    case Signal::TERM:
                        IO::write(STDOUT, "INT|TERM RECEIVED!\n");
                        // Force down
                        $this->stop(false);
                        // Break out of wait
                        break 2;
                    case Signal::QUIT:
                        IO::write(STDOUT, "QUIT RECEIVED!\n");
                        break 2;
                }
            }catch(\Exception $e){
                IO::write(STDERR, "Exception happened {$e->getMessage()}\n");
                sleep(10);
                // Log the error but ignore it otherwise...
            }
        } while(true);
        // Graceful shutdown please!
        $this->stop();
        IO::write(STDOUT, "Shutting down...\n");
    }

    public function masterSleep($timeout){
        IO::write(STDOUT, "Sleeping for $timeout\n");
        if(!IO::select(array($this->SELF_PIPE[0]), null, null, $timeout)) { return; } // early exit for interrupts

        IO::write(STDOUT, "Reading off the self pipe!!!\n");
        stream_set_blocking($this->SELF_PIPE[0], 0);
        IO::write(STDOUT, "Self pipe said '" . IO::read($this->SELF_PIPE[0], 11) . "'.\n"); // read off the stuff! 11 bytes, or perhaps just read until we get nothing...
        stream_set_blocking($this->SELF_PIPE[0], 1);
    }

    public function awakenMaster(){
        IO::write(STDOUT, "Ooh master was woken!\n");
        IO::write($this->SELF_PIPE[1], ".");
    }

    public function killEachWorker($signal){
        foreach($this->pids as $pid){
            $this->killWorker($pid, $signal);
        }
    }

    public function killWorker($pid, $signal){
        try {
            IO::write(STDOUT, "Attempting to kill $pid with $signal.\n");
            Process::kill($pid, $signal);
        } catch(\RuntimeException $e){
            // ignore kill runtime exceptions, this is due to the process already being dead...!
            IO::write(STDOUT, "Failed to kill process $pid\n");
        }
    }

    public function reapAllWorkers(){
        // Clear down all workers that have an exit status.
        do {
            try {
                list($pid, $status) = Process::wait(WNOHANG|WUNTRACED);
                // Quick exit for reaping if we've nothing to do.
                if($pid === 0){
                    IO::write(STDOUT, "Found no waiting processes\n");
                    return; // exit because we have NO pids to deal with.
                }
                IO::write(STDOUT, "Exit status: [$pid]" . pcntl_wexitstatus($status) . "\n");
                $this->removeWorker($pid);
            } catch(\RuntimeException $e){
                // Any runtime exception from process wait should be ignored, probably because we don't have any children...
                IO::write(STDOUT, "Runtime exception because process wait failed. No children?\n");
                break;
            }
        } while(true);

    }

    public function stop($graceful = true){
        while(count($this->pids)){
            $this->killEachWorker($graceful ? Signal::QUIT : Signal::TERM);
            // sleep 0.1 secs
            usleep(100000);
            $this->reapAllWorkers();
        }
        $this->killEachWorker(Signal::KILL);
    }

    private function removeWorker($pid){
        IO::write(STDOUT, "Dropping worker [$pid]\n");
        unset($this->pids[$pid]);
    }
}