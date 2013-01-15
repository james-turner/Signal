<?php

namespace IO;

class IO {

    static public function select($reads, $writes, $exceptions, $seconds){
        $microseconds = $seconds * 1000000;
        if(false === ($num = @stream_select($reads, $writes, $exceptions, 0, $microseconds))){
            /**
             * @note there is an assumption here that this will fail if we
             * receive an interrupt
             * e.g. PHP Warning:  stream_select(): unable to select [4]: Interrupted system call (max_fd=4)
             */
            // throw new IOException("An error occurred on selecting streams!\n");
            // We choose to return null here for ease of use.
            return null;
        }

        return array($reads, $writes, $exceptions);
    }

    static public function read($name, $length = null, $offset = null){
        $close = false;
        is_resource($name) || (($name = fopen($name, 'r')) && ($close = true));
        (null === $offset) || (fseek($name, $offset));
        $data = "";
        do {
            if(false === ($byte = fread($name, 1))){
                throw new IOException("Cannot read a byte!!!!\n");
            }
            $data .= $byte;
        } while(!feof($name) && ($length?(strlen($data) < $length):true) && strlen($byte) > 0);
        // exit when we reach eof or length is now length or byte is not a byte...
        if($close) fclose($name);
        return $data;
    }

    static public function write($name, $data, $length = null){
        $close = false;
//        is_resource($name) || (($name = fopen($name, 'a')) && ($close = true));
        if($length){
            fwrite($name, $data, $length);
            // flush the output?
            fflush($name);
        } else {
            fwrite($name, $data);
            // flush the output?
            fflush($name);
        }
        if($close) fclose($name);
    }

}