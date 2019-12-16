<?php

namespace ftIndex\io;

/**
 * Class InputStreamReader
 *
 * @package ftIndex\io
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/11/19 7:53 PM
 */
class InputStreamReader
{
    protected $resource;
    protected $encoding;

    /**
     * InputStreamReader constructor.
     *
     * @param resource $resource
     * @param string   $encoding
     */
    public function __construct($resource, $encoding = 'UTF-8')
    {
//        $this->content = file_get_contents('/opt/src/php-ft-index/src/analyses/morphology/russian/morph.info');
        $this->resource = $resource;
        $this->encoding = $encoding;
    }

    public function readLine()
    {
        return trim(fgets($this->resource));
    }

    public function close()
    {
        return fclose($this->resource);
    }

    /**
     * @return resource
     */
    public function getResource()
    {
        return $this->resource;
    }
}