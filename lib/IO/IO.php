<?php

namespace IO;

class IO {

    /**
     *
     *
     * @param array|null $reads
     * @param array|null $writes
     * @param array|null $exceptions
     * @param int|float $seconds
     * @return array|null
     */
    static public function select($reads, $writes, $exceptions, $seconds){
        $microseconds = null;
        if(is_float($seconds)){
            $floorSeconds = floor($seconds);
            $floatingPoint =  $seconds - $floorSeconds;
            // 1 second = 1000000 microseconds
            $microseconds = (int)($floatingPoint * 1000000);
        }

        if(empty($reads) && empty($writes) && empty($exceptions)){
            throw new \InvalidArgumentException("Read, Write, or Exceptions must contain a valid array of streams.");
        }

        if(false === ($num = @stream_select($reads, $writes, $exceptions, (int)$seconds, $microseconds))){
            /**
             * @note there is an assumption here that this will fail if
             * we receive an interrupt
             * e.g. PHP Warning:  stream_select(): unable to select [4]: Interrupted system call (max_fd=4)
             */

            // Cheaper way to catch the last error than writing a temporary error handler.
            // Requires stream_select errors to be suppressed with @
            $lastError = error_get_last();
            if(isset($lastError['message']) && false !== stripos($lastError['message'], 'interrupted system call')){
                // weird dependency on Signal stuff here...
                throw new \Signal\InterruptException($lastError['message']);
            }

            // We choose to return null here for ease of use.
            return null;
        }

        return array($reads, $writes, $exceptions);
    }

    /**
     * Performs a read from a resource or file string until
     * EOF or until length bytes have been read.
     * @param resource|string $name
     * @param null $length
     * @param null $offset
     * @return string
     * @throws IOException
     */
    static public function read($name, $length = null, $offset = null){
        $close = false;
        is_resource($name) || (($name = fopen($name, 'r')) && fseek($name, 0) && ($close = true));
        (null === $offset) || (fseek($name, $offset));

        // Confirm readable
        if(!static::readable($name)){
            throw new IOException("Unable to read from non-readable stream.");
        }

        $data = "";
        do {
            // Default to 8092 bytes as this is the default buffer size in php.
            if(false === ($chunk = fread($name, $length?:8092))){
                throw new IOException("Cannot read from stream.\n");
            }
            $data .= $chunk;
        } while(!feof($name) && ($length?(strlen($data) < $length):true) && strlen($chunk) > 0);
        // exit when we reach eof or length is now length or byte is not a byte...
        if($close) fclose($name);
        return $data;
    }

    /**
     * Splits the lines by \n
     * This will work in all filesystems (WIN/Linux)
     * It is up to the user to chomp any remaining \r from
     * the end of each line.
     * @param string|resource $name
     * @param string $separator
     * @param null $limit
     * @return array
     */
    static public function readlines($name, $separator = "\n", $limit = null){
        $data = static::read($name);
        if($limit !== null){
            return explode($separator, $data, $limit);
        } else {
            return explode($separator, $data);
        }
    }

    /**
     * Write to a file or stream an amount
     * of data of length.
     * @param $name
     * @param $data
     * @param null $length
     */
    static public function write($name, $data, $length = null){
        $close = false;
        is_resource($name) || (($name = fopen($name, 'w')) && ($close = true));

        if(!static::writeable($name)){
            throw new \RuntimeException("Unable to write to stream.");
        }

        if($length){
            $ret = fwrite($name, $data, $length);
        } else {
            $ret = fwrite($name, $data);
        }
        fflush($name);
        if($close) fclose($name);
        return $ret;
    }

    /**
     * @note this is a hack because PHP does NOT support one-directional
     * pipes correctly, so instead we return a pair of bi-directional
     * streams.
     * @return array read|write pair of pipe
     * @throws IOException
     */
    static public function pipe(){
        if(false === ($pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP))){
            throw new IOException("Unable to create Pipe pair.");
        }
        return $pair;
    }

    /**
     * @param $fd
     * @return bool
     */
    static public function readable($fd){
        $checks = array("r", "+");
        return static::containsMode($fd, $checks);
    }

    /**
     * @param $fd
     * @return bool
     */
    static public function writeable($fd){
        $checks = array('a','x','c','w','+');
        return static::containsMode($fd, $checks);
    }

    /**
     * @param $fd
     * @param $checks
     * @return bool
     */
    static private function containsMode($fd, array $checks){
        $meta = stream_get_meta_data($fd);
        $mode = $meta['mode'];

        while($check = array_shift($checks)){
            if(false !== strpos($mode, $check)){
                return true;
            }
        }
        return false;
    }

}