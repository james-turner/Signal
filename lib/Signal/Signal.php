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

    const KILL = SIGKILL; // can't handle this!
    const HUP  = SIGHUP;
    const INT  = SIGINT;
    const TERM = SIGTERM;
    const QUIT = SIGQUIT;
    const CHLD = SIGCHLD;

    /**
     * @param $signal
     * @param $block
     */
    static public function trap($signal, $block){
        if(null === $block) $block = SIG_IGN;
        pcntl_signal(self::interpretSignal($signal), $block);
    }

    /**
     * @param $signal
     * @return mixed
     */
    static private function interpretSignal($signal){
        if(is_string($signal)){
            $scopeResolutionOperator = "::";
            $signal = constant(__CLASS__ . $scopeResolutionOperator. $signal);
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