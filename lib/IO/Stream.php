<?php

namespace IO;


class Stream {

    protected $fd;
    protected $resource;

    /**
     * @param $resource - typically a file descripter in other languages, but in php known as a "resource"
     */
    public function __construct($fd, $mode = "r"){
        $this->fd = $fd;
        $resource = $fd;
        if(is_string($fd)){
            $resource = fopen($fd, $mode);
        }

        if(!is_resource($resource)){
            throw new \RuntimeException("Stream should be a valid resource!\n");
        }
        $this->resource = $resource;
    }

    public function read($length = null, $offset = null){
        return IO::read($this->resource, $length, $offset);
    }

    public function write($data, $length = null){
        IO::write($this->resource, $data, $length);
    }

    public function tryRead($length = null, $offset = null){
        $this->unblock();
        $read = $this->read($length, $offset);
        $this->block();
        return $read;
    }

    public function block(){
        stream_set_blocking($this->resource, 1);
    }

    public function unblock(){
        stream_set_blocking($this->resource, 0);
    }

}