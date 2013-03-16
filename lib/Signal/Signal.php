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
//    const STKFLT = SIGSTKFLT;
//    const CLD  = SIGCLD;
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
//    const POLL = SIGPOLL;
    const IO   = SIGIO;
//    const PWR  = SIGPWR;
    const SYS  = SIGSYS;
    const BABY = SIGBABY;

    /**
     * @var int|mixed|string
     */
    private $signal;

    /**
     *
     * @param int|mixed|string $sig - Signal
     */
    public function __construct($sig){
        $this->signal = self::interpretSignal($sig);
    }

    /**
     * Dispatch the current signal to
     * the supplied pid or to current process
     * thread if no pid supplied.
     *
     * @param null $pid
     */
    public function dispatch($pid = null){
        posix_kill($pid?:posix_getpid(), $this->signal);
        pcntl_signal_dispatch();
    }

    /**
     * Trap a signal and execute the supplied
     * closure (block) when the signal is received
     * by the underlying process.
     * @param $signal
     * @param $block
     */
    static public function trap($signal, $block){
        if(null === $block) $block = SIG_IGN;
        pcntl_signal(self::interpretSignal($signal), $block);
    }

    /**
     * Convert the signal parameter into
     * a valid SIG_* constant for usage.
     * @param string|int|mixed $signal
     * @return int
     * @throws EINVAL
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
                    throw new Errno\EINVAL("Unknown signal $signal.");
                }
            }
        }
        if(is_int($signal)){
            if($signal < 1 || $signal > 31){
                throw new Errno\EINVAL("Invalid signal $signal.");
            }
        }
        return $signal;
    }

    /**
     * Block off the supplied signals from
     * being received by the process thread.
     * @param array $signals
     */
    public static function block(array $signals){
        $signals = array_map(array(__CLASS__, 'interpretSignal'), $signals);
        pcntl_sigprocmask(SIG_BLOCK, $signals, $old);
    }

    /**
     * Unblock an array of signals so that
     * the process thread will receive them.
     * @param array $signals
     */
    public static function unblock(array $signals){
        $signals = array_map(array(__CLASS__, 'interpretSignal'), $signals);
        pcntl_sigprocmask(SIG_UNBLOCK, $signals, $old);
    }
}

/**
 * Check for pcntl extension loading.
 */
if(!extension_loaded('pcntl')){
    throw new \Exception("Required pcntl extension.");
}