<?php

namespace IO;

use IO\IO;

class STDOUT extends \php_user_filter {

    const RAW = STDOUT;
    protected $defaultMode = 'a';

    /**
     * @var string
     */
    private $filename;

    /**
     * @var resource
     */
    private $resource;

    /**
     * @var string
     */
    private $origOutputBuffer;

    /**
     * Instance of the appended
     * filter, for use when removing
     * using stream_filter_remove()
     * @var resource
     */
    static protected $registered;

    /**
     *
     * @param resource $in
     * @param resource $out
     * @param int $consumed bytes count of how much
     * @param $closing
     * @return int|void
     */
    public function filter($in, $out, &$consumed, $closing) {

        while($bucket = stream_bucket_make_writeable($in)){
            $data = $bucket->data;
            // empty the bucket
            $bucket->data = "";
            $consumed += $bucket->datalen;
            stream_bucket_prepend($out, $bucket);
            IO::write($this->resource, $data);
        }
        return PSFS_PASS_ON;
    }

    /**
     * onCreate: run when the filter
     * is appended
     */
    public function onCreate () {
        $fileOrResource = array_shift($this->params);
        $mode = array_shift($this->params);
        if(is_string($fileOrResource)){
            $this->filename = $fileOrResource;
            $fileOrResource = fopen($fileOrResource, ($mode?:$this->defaultMode));
        }
        $this->resource = $fileOrResource;
        /**
         * Manipulate the output buffer
         * to catch all echo/print style
         * stream writing.
         * @note uses 2 bytes chunk limit so we
         * are always being called on anything
         * bigger than a single character.
         * - 1 byte is not a good value "< php5.4"
         * it gets interpreted to 4096 in php5.3
         * (a magic value)
         * @note only apply this to stdout because
         * stderr is NOT part of stdout buffering
         * in php land.
         */
        if(get_class($this) === __CLASS__){
            ob_start(function($buffer, $bit)use(&$fileOrResource){
                IO::write($fileOrResource, $buffer);
                return "";
            },2);
            // Implicit flush should be on (should always be on by default in CLI anyway!)
            $this->origOutputBuffer = ini_get('output_buffering');
            ob_implicit_flush(true);
        }

        $this->registerShutdownHandler();

        return true;
    }

    /**
     * Registers a shutdown handler to make sure
     * the stream filters are removed correctly
     * before exiting PHP.
     */
    private function registerShutdownHandler(){
        $registered =& static::$registered;
        /**
         * Shutdowns are run in order of registration.
         * Therefore if more than 1 reopen() is performed
         * the latter filters will be shutdown last.
         */
        register_shutdown_function(function()use(&$registered){
            /**
             * If we're still registered when shutdown
             * events occur we ought to perform some cleanup!
             */
            if($registered){
                stream_filter_remove($registered);
                $registered = null;
            }
        });
    }

    /**
     * onClose: run when the
     * is shutdown
     */
    public function onClose () {
        if(get_class($this) === __CLASS__){
            // Revert the original output buffer setting.
            ob_implicit_flush($this->origOutputBuffer);
            // Flush the buffer
            // using ob_get_flush() instead of ob_end_flush() so no warning about empty buffers is thrown.
            ob_get_flush();
        }

        // Close our resource but only if it's ours!
        if($this->filename && is_resource($this->resource)) fclose($this->resource);
    }

    /**
     *
     * @param string|resource $file_or_stream
     * @param null|string $mode
     */
    static public function reopen($file_or_stream, $mode = null){
        if(static::$registered){
            stream_filter_remove(static::$registered);
            static::$registered = null;
        }

        if($file_or_stream !== static::RAW){
            $read_write = STREAM_FILTER_WRITE;
            $params = array($file_or_stream, $mode);

            // Enforce registration of called class as a stream filtration.
//            stream_filter_register(get_called_class(), get_called_class());

            static::$registered = stream_filter_append(static::RAW, get_called_class(), $read_write, $params);
        }
    }
}

// Register filter
defined("PHPOUT") || define("PHPOUT", fopen('php://output','w'));
stream_filter_register("IO\\STDOUT", "IO\\STDOUT");
