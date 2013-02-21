<?php

date_default_timezone_set('GMT');

set_include_path(implode(PATH_SEPARATOR, array(
    realpath(__DIR__ ."/lib"),
    get_include_path(),
)));

spl_autoload_register(function($className){
    $path = str_replace("\\", "/", $className) . ".php";
    require_once $path;
});