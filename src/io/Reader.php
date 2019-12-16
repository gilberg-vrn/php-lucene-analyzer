<?php

namespace ftIndex\io;

/**
 * Class Reader
 *
 * @package ftIndex\io
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    8/29/19 6:21 PM
 */
class Reader
{
    /**
     * @var array
     */
    protected $data;

    /**
     * @var int
     */
    protected $position = 0;
    /**
     * @var int
     */
    protected $dataSize = 0;

    public function __construct(string $data)
    {
        $this->data = preg_split('//u', $data, null, PREG_SPLIT_NO_EMPTY);
        $this->dataSize = count($this->data);
    }

    public function read(&$buf, int $offset, int $count)
    {
        if (strlen($buf) < $offset) {
            $buf = str_pad($buf, $offset - 1, "\0");
        }

        if ($this->position + 1 >= $this->dataSize) {
            return -1;
        }

        $string = implode('', array_slice($this->data, $this->position, $count));
        $this->position += $count;
        $writeCount = $count;
        $buf = $this->substr_replace($buf, $string, $offset);
        
        /*$writeCount = 0;
        for (; $this->position < $this->dataSize; $this->position++) {
            $buf[$offset++] = $this->data[$this->position];
            var_dump($buf);
            $writeCount++;
        }*/

        return $writeCount;
    }

    public function close()
    {
        return true;
    }

    private function substr_replace($input, $replacement, $offset, $length = null)
    {
        if ($offset > 0) {
            $prefix = mb_substr($input, 0, $offset - 1);
        } else {
            $prefix = '';
        }

        if ($length !== null) {
            $length = min(mb_strlen($replacement), $length);
        } else {
            $length = mb_strlen($replacement);
        }

        if ($offset + $length < mb_strlen($input)) {
            $postfix = mb_substr($input, $offset + $length);
        } else {
            $postfix = '';
        }

        return $prefix . $replacement . $postfix;
    }
}