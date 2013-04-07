<?php

require_once realpath(__DIR__."/../../../bootstrap.php");

use IO\STDOUT;
use IO\STDERR;

class STDOUTTest extends PHPUnit_Framework_TestCase {

    private $tmpFilename;

    public function setUp(){
        $this->tmpFilename  = $this->createTmpFilename();
        $this->removeTestFile($this->tmpFilename);
    }

    public function tearDown(){

        $this->removeTestFile($this->tmpFilename);
        // Reset the stdout and stderr.
        /**
         * Naughty clear down because i'm
         * assuming that ::reopen(orig) will
         * correctly remove the registered
         * filter.
         */
        STDOUT::reopen(STDOUT);
        STDERR::reopen(STDERR);
    }

    public function testRedirectingSTDOUTWrites(){

        STDOUT::reopen($this->tmpFilename,'w+');

        fwrite(STDOUT, "hello");

        // Open tmp file for reading
        $data = fread(fopen($this->tmpFilename,'r'), 1024);

        $this->assertEquals('hello', $data);
    }


    public function testRedirectingEchoBufferedWrites(){

        STDOUT::reopen($this->tmpFilename,'w+');

        echo "hello";

        // Open tmp file for reading
        $data = fread(fopen($this->tmpFilename,'r'), 1024);

        $this->assertEquals('hello', $data);

    }

    public function testResettingToOriginalStream(){

        STDOUT::reopen($this->tmpFilename,'w+');

        echo "hello";

        // reset the stdout to original.
        STDOUT::reopen(STDOUT);

        ob_start();
        echo "world";
        $echo = ob_get_contents();
        ob_end_clean();

        // Open tmp file for reading
        $data = fread(fopen($this->tmpFilename,'r'), 1024);

        $this->assertEquals('hello', $data);
        $this->assertNotEquals($echo, $data);


    }



    public function testMultipleReopens(){

        $tmpOne = $this->createTmpFilename();
        $tmpTwo = $this->createTmpFilename();
        STDOUT::reopen($tmpOne, 'w+');

        fwrite(STDOUT, "hello");

        STDOUT::reopen($tmpTwo, 'w+');

        fwrite(STDOUT, "world");

        $data1 = fread(fopen($tmpOne, 'r'), 1024);
        $data2 = fread(fopen($tmpTwo, 'r'), 1024);

        $this->assertEquals('hello', $data1);
        $this->assertEquals('world', $data2);

    }

    public function testSTDERRRedirect(){

        STDERR::reopen($this->tmpFilename,'w+');

        fwrite(STDERR, "hello");

        // Open tmp file for reading
        $data = fread(fopen($this->tmpFilename,'r'), 1024);

        $this->assertEquals('hello', $data);
        $this->assertNotEquals('world', $data);

    }

    public function testRedirectingCommonStreams(){

        STDERR::reopen($this->tmpFilename, 'w+');
        // essentially redirects STDERR to STDOUT!
        STDOUT::reopen(STDERR);

        fwrite(STDOUT, "hello");

        // Open tmp file for reading
        $data = fread(fopen($this->tmpFilename,'r'), 1024);

        $this->assertEquals('hello', $data);
    }

    /**
     * @bug
     * Previously ob_start() was being invoked for STDERR which
     * meant that echo content (which is stdout!) was being captured by
     * STDERR redirection.
     */
    public function testStderrDoesNotImpactStdoutBuffering(){

        $tmp1 = $this->createTmpFilename();
        $tmp2 = $this->createTmpFilename();

        STDOUT::reopen($tmp1, 'w+b');
        STDERR::reopen($tmp2, 'w+b');

        echo "hello";
        fwrite(STDERR, "world");

        // Open tmp file for reading
        $data1 = fread(fopen($tmp1,'r'), 1024);
        $data2 = fread(fopen($tmp2,'r'), 1024);

        $this->assertEquals('hello', $data1);
        $this->assertEquals('world', $data2);
    }

    /**
     * error_log calls appear not to be able to be handled.
     * @markTestSkipped
     */
    public function testRedirectingErrorLog(){

        $this->markTestSkipped("error_log does NOT perform a normal write to STDERR via the SAPI handler. Thereby it is uncontrollable.");

        STDERR::reopen($this->tmpFilename,'w+');

        // SHOULD log to the SAPI error logger: stderr on cli.
        echo "error log is" .ini_get('error_log') . "\n";
        error_log("oops");

        STDERR::reopen(STDERR);

        // Open tmp file for reading
        $data = fread(fopen($this->tmpFilename,'r'), 1024);

        $this->assertEquals('oops', $data);


    }

    public function testRedirectingTriggerError(){

        STDERR::reopen($this->tmpFilename,'w+');

        trigger_error("oops");

        STDERR::reopen(STDERR);

        // Open tmp file for reading
        $data = fread(fopen($this->tmpFilename,'r'), 1024);

        $this->assertEquals('oops', $data);


    }

    public function testVarDump(){

        // var_dump() should be on STDOUT
        STDOUT::reopen($this->tmpFilename,'w+');

        var_dump("oops");

        // Open tmp file for reading
        $data = fread(fopen($this->tmpFilename,'r'), 1024);

        // var_dump() adds a newline character
        $this->assertEquals("string(4) \"oops\"\n", (string)$data);
    }

    public function testPrintR(){

        // var_dump() should be on STDOUT
        STDOUT::reopen($this->tmpFilename,'w+');

        print_r("oops");

        // Open tmp file for reading
        $data = fread(fopen($this->tmpFilename,'r'), 1024);

        $this->assertEquals('oops', (string)$data);

    }


    public function testReopenWithOriginatingStream(){

        STDOUT::reopen(STDOUT);

        $data = fread(fopen($this->tmpFilename,'x+'), 1024);
        $this->assertEquals('', $data);

    }

    public function testStreamWriting(){

        $fd = fopen($this->tmpFilename, 'w+');

        STDOUT::reopen($fd);

        fwrite(STDOUT, "yeah");

        $data = fread(fopen($this->tmpFilename,'r'), 1024);
        $this->assertEquals('yeah', $data);

    }


    public function testEchoingWithoutReopeningAfter(){

        STDOUT::reopen($this->tmpFilename, 'w+');

        echo 'yeah';

        $data = fread(fopen($this->tmpFilename,'r'), 1024);
        $this->assertEquals('yeah', $data);
    }

    /**
     * @bug
     * We were closing the passed stream which
     * of course leads to problems attempting to use the
     * stream you passed after having used ::reopen()
     */
    public function testStreamRemainsOpen(){

        $fd = fopen('php://memory','w+');

        STDOUT::reopen($fd);

        fwrite(STDOUT, "hello world");

        // Reopen to force closing on the filter.
        STDOUT::reopen(STDOUT);

        // rewind
        fseek($fd, 0);

        $this->assertEquals('hello world',fread($fd, 1024));

    }

    /**
     * In the event that stdout is redirected but
     * buffering occurs after a redirect then we
     * shouldn't really expect anything on the actual
     * stdout.
     */
    public function testDoubleBuffering(){
        // redirect stdout to tmp.
        STDOUT::reopen($this->tmpFilename);

        ob_start();
        echo "hello world";
        $echo = ob_get_contents();
        ob_get_clean();

        $data = fread(fopen($this->tmpFilename, 'r'),8192);
        $this->assertEquals("", $data);
        $this->assertEquals($echo, "hello world");
    }

    /**
     * @helper
     */
    private function removeTestFile($filename){
        file_exists($filename) and unlink($filename);
    }

    /**
     * @helper
     * @return string
     */
    private function createTmpFilename(){
        return tempnam(sys_get_temp_dir(), 'phpunit.stdout.test.');
    }

}