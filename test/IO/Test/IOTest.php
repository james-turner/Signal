<?php

namespace IO\Test;

use IO\IO;
use IO\InterruptException;

require_once realpath(__DIR__ ."/../../../bootstrap.php");

class IOTest extends \PHPUnit_Framework_TestCase {

    private $fileDescriptors = array();

    public function tearDown(){
        foreach($this->fileDescriptors as $stream){
            if(is_resource($stream))
                fclose($stream);
        }
    }

    public function testEmptyReadOnEndOfFile(){

        $fd = $this->createFileDescriptor('php://memory', 'w+');
        // read
        fwrite($fd, "hello world");

        $content = IO::read($fd);

        $this->assertEquals("", $content);

    }

    public function testBlockedRead(){

        list($read, $write) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($write, "hello world");

        // timeout the stream read.
        stream_set_timeout($read, 0, 200000);
        $actual = IO::read($read);

        $this->assertEquals("hello world", $actual);

        fclose($read);
        fclose($write);
    }

    public function testMultiLineRead(){

        $tmp = tempnam(sys_get_temp_dir(), 'phpunit_');
        $fd = $this->createFileDescriptor($tmp, 'w+');
        fwrite($fd, implode(PHP_EOL, array("hello", "world")));
        rewind($fd);

        $lines = IO::readlines($fd);

        $this->assertEquals(array("hello", "world"), $lines);
    }

    public function testLimitedMultiLineRead(){
        $tmp = tempnam(sys_get_temp_dir(), 'phpunit_');
        $fd = $this->createFileDescriptor($tmp, 'w+');
        fwrite($fd, implode(PHP_EOL, array("hello", "world")));
        rewind($fd);

        $lines = IO::readlines($fd, PHP_EOL, 1);

        $this->assertEquals(array("hello".PHP_EOL."world"), $lines);
    }

    public function testUnreadable(){

        $this->setExpectedException('RuntimeException');

        $tmp = tempnam(sys_get_temp_dir(), 'phpunit_');
        $fd = $this->createFileDescriptor($tmp, 'w');

        IO::read($fd);

    }

    public function testReadLength(){

        list($read, $write) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        fwrite($write, "hello world");

        $content = IO::read($read, 11);

        $this->assertEquals("hello world", $content);

        fclose($read);
        fclose($write);
    }

    public function testReadOffset(){

        $stream = $this->createFileDescriptor('php://memory', 'w+');

        fwrite($stream, "hello world");

        $content = IO::read($stream, 5, 6);

        $this->assertEquals("world", $content);

    }

    public function testRawFileRead(){

        $tmp = tempnam(sys_get_temp_dir(), 'phpunit_');
        $fh = fopen($tmp, "w");
        fwrite($fh, "hello world");
        fclose($fh);

        // Using filename string
        $read = IO::read($tmp);

        $this->assertEquals("hello world", $read);

    }

    public function testRawFileWrite(){

        $tmp = tempnam(sys_get_temp_dir(), 'phpunit_');

        IO::write($tmp, "hello world");

        $fh = $this->createFileDescriptor($tmp, 'r');
        $read = fread($fh, 1024);

        $this->assertEquals('hello world', $read);
    }

    public function testWriteLength(){

        $tmp = tempnam(sys_get_temp_dir(), 'phpunit_');

        $length = IO::write($tmp, "hello world", 9);

        $fh = $this->createFileDescriptor($tmp, 'r');
        $read = fread($fh, 1024);

        $this->assertEquals('hello wor', $read);
        $this->assertEquals(9, $length);

    }

    /**
     * @param $mode
     * @param $readOrWrite
     * @dataProvider modeProvider
     */
    public function testReadWriteModes($mode, $readOrWrite){

        if(false !== strpos($mode, 'x')){
            do {
                // generate random file name for the 'x' type modes
                $tmp = sys_get_temp_dir() . "_phpunit_fd_".rand(0,999);
            } while(file_exists($tmp));
        } else {
            $tmp = $this->tmpFile();
        }

        $fd = $this->createFileDescriptor($tmp, $mode);
        $this->assertTrue(IO::$readOrWrite($fd));
    }

    /**
     *
     */
    public function testPipe(){

        list($read, $write) = IO::pipe();

        fwrite($write, "hello world");
        $data = fread($read, 12);

        $this->assertEquals("hello world", $data);

        fclose($read);
        fclose($write);
    }

    public function testInterruptedSelect(){

        list($r, $w) = IO::pipe();

        $pid = pcntl_fork();
        if(-1 === $pid){
            throw new \RuntimeException("Failed to fork a process during test.");
        }
        if(0 === $pid){
            // child
            try {
                // In order to facilitate this test,
                // we need to catch this signal because
                // otherwise it kills the process instantaneously,
                // and thus no signal interrupt occurs.
                declare(ticks=1);
                pcntl_signal(SIGINT, function(){});
                IO::select(array($r), null, null, 3);
            } catch(InterruptException $e){
                fwrite(STDOUT, $e->getMessage()."\n");
                fwrite(STDOUT, "caught exception\n");
                fwrite($w, serialize("exception"));
                fclose($r);
            }
            fclose($w);
            exit;
        } else {
            // parent
            fclose($w);
            sleep(1);
            // kill off the existing pid
            posix_kill($pid, SIGINT);
            // wait for proc to exit: The only chance we have to contain this is to vaporize every living thing aboard that aircraft.
            pcntl_waitpid($pid, $status);
            $decode = fread($r, 8192);
            $ex = unserialize($decode);
            $this->assertEquals("exception", $ex);
            fclose($r);
        }


    }

    public function testInvalidStreams(){

        $this->setExpectedException('InvalidArgumentException');

        IO::select(null, null, null, 2);

    }

    public function testTimeoutAdheredTo(){

        list($r, $w) = IO::pipe();
        $start = microtime(true);
        IO::select(array($r), null, null, 1);
        $stop = microtime(true);
        $diff = $stop - $start;

        $check = $diff > 1.0 && $diff < 1.5;
        $this->assertTrue($check);

        fclose($r);
        fclose($w);
    }

    public function testFloatTimeout(){

        list($r, $w) = IO::pipe();
        $start = microtime(true);
        IO::select(array($r), null, null, 0.2);
        $stop = microtime(true);
        $diff = $stop - $start;

        $check = ($diff > 0.19 && $diff < 0.21);
        $this->assertTrue($check);

        fclose($r);
        fclose($w);

    }


    /**
     *
     */
    public function modeProvider(){
        $readMode = 'readable';
        $writeMode = 'writeable';
        return array(
            array('r',$readMode),
            array('w+',$readMode),
            array('r+',$readMode),
            array('a+',$readMode),
            array('c+',$readMode),
            array('x+',$readMode),
            array('w',$writeMode),
            array('w+',$writeMode),
            array('a',$writeMode),
            array('a+',$writeMode),
            array('c',$writeMode),
            array('c+',$writeMode),
            array('x',$writeMode),
        );
    }

    /**
     * @helper
     * @param $desc
     * @param $mode
     * @return resource
     */
    private function createFileDescriptor($desc, $mode){
        $this->fileDescriptors[] = $fd = fopen($desc, $mode);
        return $fd;
    }

    private function tmpFile(){
        return tempnam(sys_get_temp_dir(), 'phpunit_');
    }

}