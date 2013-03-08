<?php

/**
 * @note THIS SHOULD GO AT THE TOP OF WHATEVER YOU ARE DOING.
 * doing this here appears to work in Ubuntu, but does NOT work
 * on MAC OSX.
 *
 * Usage of Signal handlers requires
 * declare(ticks=1) to be defined
 * @see http://php.net/manual/en/function.pcntl-signal.php
 */
declare(ticks=1);

namespace Signal;

class Signal {

    const DFL  = SIG_DFL;
    const HUP  = SIGHUP;
    const INT  = SIGINT;
    const QUIT = SIGQUIT;
    const ILL  = SIGILL;
    const TRAP = SIGTRAP;
    const ABRT = SIGABRT;
    const IOT  = SIGIOT;
    const BUS  = SIGBUS;
    const FPE  = SIGFPE;
    const KILL = SIGKILL; // You can't handle the truth!!
    const USR1 = SIGUSR1;
    const SEGV = SIGSEGV;
    const USR2 = SIGUSR2;
    const PIPE = SIGPIPE;
    const ALRM = SIGALRM;
    const TERM = SIGTERM;
    const STKFLT = SIGSTKFLT;
    const CLD  = SIGCLD;
    const CHLD = SIGCHLD;
    const CONT = SIGCONT;
    const STOP = SIGSTOP;
    const TSTP = SIGTSTP;
    const TTIN = SIGTTIN;
    const TTOU = SIGTTOU;
    const URG  = SIGURG;
    const XCPU = SIGXCPU;
    const XFSZ = SIGXFSZ;
    const VTALRM = SIGVTALRM;
    const PROF = SIGPROF;
    const WINCH = SIGWINCH;
    const POLL = SIGPOLL;
    const IO   = SIGIO;
    const PWR  = SIGPWR;
    const SYS  = SIGSYS;
    const BABY = SIGBABY;

    private $signal;

    public function __construct($sig){
        $this->signal = self::interpretSignal($sig);
    }

    public function dispatch($pid = null){
        $pid || ($pid = posix_getpid());
        posix_kill($pid, $this->signal);
        pcntl_signal_dispatch();
    }

    /**
     * @param $signal
     * @param $block
     */
    static public function trap($signal, $block){
        if(null === $block) $block = SIG_IGN;
        pcntl_signal(self::interpretSignal($signal), $block);
    }

    /**
     * @param string|int|mixed $signal
     * @return int
     */
    static private function interpretSignal($signal){
        if(is_string($signal)){
            // early exit in case this is a system signal or a full string of class constant.
            if(defined($signal)){
                return constant($signal);
            } else {
                $scopeResolutionOperator = "::";
                $stringSignal = __CLASS__ . $scopeResolutionOperator. $signal;
                if(defined($stringSignal)){
                    $signal = constant($stringSignal);
                } else {
                    throw new UnknownSignal();
                }
            }
        }
        if(is_int($signal)){
            if($signal < 1 || $signal > 31){
                throw new UnknownSignal("Invalid signal $signal.");
            }
        }
        return $signal;
    }
}

/**
 * Check for pcntl extension loading.
 */
if(!extension_loaded('pcntl')){
    throw new \Exception("Required pcntl extension.");
}