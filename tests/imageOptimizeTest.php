<?php
use PHPUnit\Framework\TestCase;

class imageOptimizeTest extends TestCase
{
    public $test_obj;
    public $root_dir;

    public function __construct()
    {
        parent::__construct();
        $this->test_obj = new ImageOptimizer\ImageOptimizer();
        $this->root_dir = dirname(__FILE__);
    }

    /** @test */
    public function fileNotExists()
    {
        $file = $this->root_dir. '/files/Layer_121.png';
        $this->assertFalse($this->test_obj->optimize($file));
    }

    /** @test */
    public function invalidFile()
    {
        $file = $this->root_dir. '/files/blog.txt';
        $this->assertFalse($this->test_obj->optimize($file));
    }

    /** @test */
    public function filesizeExceeded()
    {
        $file = $this->root_dir. '/files/Snake_River.jpg';
        $this->assertFalse($this->test_obj->optimize($file));
    }
}
