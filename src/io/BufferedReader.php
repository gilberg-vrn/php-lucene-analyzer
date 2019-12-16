<?php

namespace ftIndex\io;

/**
 * Class BufferedReader
 *
 * @package ftIndex\io
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/11/19 7:55 PM
 */
class BufferedReader
    extends InputStreamReader
{
    protected $inputStreamReader;
    protected $lineCounter = 0;
    protected $content;
    protected $lines;

    public function __construct(InputStreamReader $inputStreamReader)
    {
        $this->inputStreamReader = $inputStreamReader;
    }

    public function readLine()
    {
        if ($this->lines === null) {
            $resource = $this->getInputStreamReader()->resource;
            while(!feof($resource)) {
                $this->content .= fread($resource, 32484);
            }
            $this->lines = explode("\n", $this->content);
            unset($this->content);
        }

        return $this->lines[$this->lineCounter++];
    }

    public function close()
    {
        $this->inputStreamReader->close();
    }

    /**
     * @return InputStreamReader
     */
    public function getInputStreamReader(): InputStreamReader
    {
        return $this->inputStreamReader;
    }

    /**
     * @return int
     */
    public function getLineCounter(): int
    {
        return $this->lineCounter;
    }
}