<?php

namespace Socket;

class Server extends Socket {

    public function __construct(){
        // override the parent construct and DO NOT invoke it!
    }

    public function listen($address){
        if(false === ($this->resource = stream_socket_server($address, $errno, $errstr))){
            throw new \RuntimeException("Failed to listen on $address with error '[$errno]$errstr'");
        }
        return $this->resource;
    }
}