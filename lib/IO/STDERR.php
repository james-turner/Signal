<?php

namespace IO;

class STDERR extends STDOUT {

    const RAW = STDERR;
    static public $registered;
}

stream_filter_register("IO\\STDERR", "IO\\STDERR");