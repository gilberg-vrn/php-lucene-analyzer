<?php

namespace ftIndex\analyses\hunspell;

/**
 * Class ReverseBytesReader
 *
 * @package ftIndex\analyses\hunspell
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/13/19 5:33 PM
 */
class ReverseBytesReader extends BytesReader
{
    private $bytes;
    private $pos;

    public function __construct($bytes)
    {
        $this->bytes = $bytes;
    }

    public function readByte(): int
    {
        return $this->bytes[$this->pos--];
    }

    public function readBytes(&$b, $offset, $len, $useBuffer = false): void
    {
        for ($i = 0; $i < $len; $i++) {
            $b[$offset + $i] = $this->bytes[$this->pos--];
        }
    }

    public function skipBytes($count): void
    {
        $this->pos -= $count;
    }

    public function getPosition(): int
    {
        return $this->pos;
    }

    public function setPosition($pos): void
    {
        $this->pos = (int)$pos;
    }

    public function reversed(): bool
    {
        return true;
    }
}