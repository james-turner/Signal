<?php

namespace Socket;

class Server extends Socket {

    public function __construct(){
        // override the parent construct and DO NOT invoke it!
//        $this->resource = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    }

    public function bind($address, $port){
//        socket_bind($this->resource, $address, $port);
    }

    public function listen($address){
        $parts = parse_url($address);
        $this->bind($parts["host"], $parts["port"]);
        if(false === ($this->resource = stream_socket_server($address, $errno, $errstr))){
//        if(false === (socket_listen($this->resource, $backlog = 1024))){
//            $errno = socket_last_error();
//            $errstr = socket_strerror($errno);
            throw new \RuntimeException("Failed to listen on $address with error '[$errno]$errstr'");
        }
        return $this->resource;
    }
}