<?php

namespace ftIndex\io;

/**
 * Class OutputStreamWriter
 *
 * @package ftIndex\io
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/11/19 7:58 PM
 */
class OutputStreamWriter
{

    protected $resource;
    protected $encoding;

    public function __construct($resource, $encoding = 'UTF-8')
    {
        $this->resource = $resource;
        $this->encoding = $encoding;
    }

    public function write(string $string)
    {
        return fwrite($this->resource, $string);
    }

    public function close()
    {
        return fclose($this->resource);
    }
}