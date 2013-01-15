<?php

namespace IO;


class IOStream {

    public $resource;

    public function __construct($resource){
        if(!is_resource($resource)){
            throw new \RuntimeException("Stream should be a valid resource!\n");
        }
        $this->resource = $resource;
    }

    public function read($length = null, $offset){
        return IO::read($this->resource, $length, $offset);
    }

    public function write($data, $length = null){
        IO::write($this->resource, $data, $length);
    }

    public function tryRead($length = null, $offset = null){
        $this->setBlocking();
        $read = $this->read($length, $offset);
        $this->setBlocking(false);
        return $read;
    }

    public function setBlocking($block = true){
        stream_set_blocking($this->resource, (int)$block);
    }

}