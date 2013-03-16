<?php

/**
 * Usage of Signal handlers required
 * declare(ticks=1) to be defined
 * @see http://php.net/manual/en/function.pcntl-signal.php
 */
declare(ticks=1);

namespace Process;

class Process {

    /**
     * The current process id.
     * @return int
     */
    static public function pid(){
        return posix_getpid();
    }

    /**
     * @param Closure|null $block
     * @return int
     * @throws \RuntimeException
     */
    static public function fork($block = null){
        $pid = pcntl_fork();
        if($pid === 0){
            (is_string($block) || is_array($block)) && call_user_func($block);
            is_callable($block) && $block();
        } elseif($pid < 0){
            throw new ForkException("Could not create child process!");
        }
        return $pid;
    }

    /**
     * @param int|null $options
     * @return array containing pid,status
     * @throw RuntimeException
     */
    static public function wait($options = null){
        $pid = pcntl_wait($status, $options);
        if($pid === -1){
            // Possibility that this will occur if no child processes occur!
            throw new Errno\ECHILD();
        }
        return array($pid, pcntl_wexitstatus($status));
    }

    /**
     * @param int|string $pid
     * @param int $signal
     * @throws \RuntimeException
     */
    static public function kill($pid, $signal = SIGKILL){
        if(false === posix_kill($pid, $signal)){
            throw new Errno\ESRCH();
        }
        /**
         * unsure about this call
         */
//        pcntl_signal_dispatch();
    }

}