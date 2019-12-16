<?php

namespace ftIndex\store;

use ftIndex\fst\Util;

/**
 * Class ByteArrayDataInput
 *
 * @package ftIndex\store
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/18/19 7:33 PM
 */
final class ByteArrayDataInput extends DataInput
{

    private $bytes;

    private $pos;
    private $limit;

    public function __construct(array $bytes = null, int $offset = 0, int $len = null)
    {
        $this->reset($bytes, $offset, $len);
    }

    public function reset(array $bytes = null, int $offset = 0, int $len = null)
    {
        $this->bytes = $bytes ?? [];
        $this->pos = $offset;
        $this->limit = $offset + ($len ?? count($this->bytes));
    }

    // NOTE: sets pos to 0, which is not right if you had
    // called reset w/ non-zero offset!!
    public function rewind()
    {
        $this->pos = 0;
    }

    public function getPosition()
    {
        return $this->pos;
    }

    public function setPosition(int $pos)
    {
        $this->pos = $pos;
    }

    public function length()
    {
        return $this->limit;
    }

    public function eof()
    {
        return $this->pos == $this->limit;
    }

    public function skipBytes($count)
    {
        $this->pos += $count;
    }

    public function readShort()
    {
        return (int)((($this->bytes[$this->pos++] & 0xFF) << 8) | ($this->bytes[$this->pos++] & 0xFF));
    }

    public function readInt()
    {
        return (($this->bytes[$this->pos++] & 0xFF) << 24) | (($this->bytes[$this->pos++] & 0xFF) << 16)
            | (($this->bytes[$this->pos++] & 0xFF) << 8) | ($this->bytes[$this->pos++] & 0xFF);
    }

    public function readLong()
    {
        $i1 = (($this->bytes[$this->pos++] & 0xff) << 24) | (($this->bytes[$this->pos++] & 0xff) << 16) |
            (($this->bytes[$this->pos++] & 0xff) << 8) | ($this->bytes[$this->pos++] & 0xff);
        $i2 = (($this->bytes[$this->pos++] & 0xff) << 24) | (($this->bytes[$this->pos++] & 0xff) << 16) |
            (($this->bytes[$this->pos++] & 0xff) << 8) | ($this->bytes[$this->pos++] & 0xff);
        return ($i1 << 32) | ($i2 & 0xFFFFFFFF);
    }

    public function readVInt()
    {
        $b = $this->bytes[$this->pos++];
        if ($b >= 0) {
            return $b;
        }
        $i = $$b & 0x7F;
        $b = $this->bytes[$this->pos++];
        $i |= ($b & 0x7F) << 7;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->bytes[$this->pos++];
        $i |= ($b & 0x7F) << 14;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->bytes[$this->pos++];
        $i |= ($b & 0x7F) << 21;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->bytes[$this->pos++];
        // Warning: the next ands use 0x0F / 0xF0 - beware copy/paste errors:
        $i |= ($b & 0x0F) << 28;
        if (($b & 0xF0) == 0) {
            return $i;
        }
        throw new \Exception("Invalid vInt detected (too many bits)");
    }

    public function readVLong($allowNegative = false)
    {
        $b = $this->bytes[$this->pos++];
        if ($b >= 0) {
            return $b;
        }
        $i = $b & 0x7F;
        $b = $this->bytes[$this->pos++];
        $i |= ($b & 0x7F) << 7;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->bytes[$this->pos++];
        $i |= ($b & 0x7F) << 14;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->bytes[$this->pos++];
        $i |= ($b & 0x7F) << 21;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->bytes[$this->pos++];
        $i |= ($b & 0x7F) << 28;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->bytes[$this->pos++];
        $i |= ($b & 0x7F) << 35;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->bytes[$this->pos++];
        $i |= ($b & 0x7F) << 42;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->bytes[$this->pos++];
        $i |= ($b & 0x7F) << 49;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->bytes[$this->pos++];
        $i |= ($b & 0x7F) << 56;
        if ($b >= 0) {
            return $i;
        }
        throw new \Exception("Invalid vLong detected (negative values disallowed)");
    }

    // NOTE: AIOOBE not EOF if you read too much
    public function readByte()
    {
        return $this->bytes[$this->pos++];
    }

    // NOTE: AIOOBE not EOF if you read too much
    public function readBytes(&$b, $offset, $len, $useBuffer = false)
    {
        Util::arraycopy($this->bytes, $this->pos, $b, $offset, $len);
        $this->pos += $len;
    }
}