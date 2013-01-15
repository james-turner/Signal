<?php

set_include_path(implode(PATH_SEPARATOR, array(
    realpath(__DIR__ . '/../lib/'),
    get_include_path(),
)));

spl_autoload_register(function($className){
    $file = str_replace("\\", "/", $className) . ".php";
    require_once $file;
});

use \Signal\Signal;

class SignalTest extends PHPUnit_Framework_TestCase {

    /**
     * @test
     */
    public function trapASignal(){

        $var1 = "hello";

        Signal::trap("SIGALRM", function()use(&$var1){
            $var1 = "world";
        });

        pcntl_alarm(5);
        sleep(10);

        $this->assertEquals($var1, "world");

    }

}
