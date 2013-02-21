<?php

namespace Socket;

use IO\Stream;

class Socket extends Stream {

    /**
     * Non-blocking call to accept on current socket stream
     * @return resource
     */
    public function tryAccept(){
        is_resource($this->resource) && ($this->unblock());
        try {
            $accepted = $this->accept();
        } catch(\Exception $e){
            $accepted = null;
        }
        is_resource($this->resource) && ($this->block());
        return $accepted;
    }

    /**
     * Blocking call to accept on current socket stream
     * @return resource
     * @throws \RuntimeException
     */
    public function accept(){
        if(false === ($client = @stream_socket_accept($this->resource, null, $peername))){
            throw new \RuntimeException("Unable to grab accepted client.\n");
        }
        return $client;
    }

    /**
     * @return array
     * @throws \RuntimeException
     */
    static public function pair(){
        if(false === ($pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP))){
            throw new \RuntimeException("Could not create socket pair!\n");
        }
        return $pair;
    }
}