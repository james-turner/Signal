<?php

namespace IO;

class NoEntityException extends IOException {
    public function __construct($message = "No such file or directory", $code = 0, $previous = null){
        parent::__construct($message, $code, $previous);

    }
}