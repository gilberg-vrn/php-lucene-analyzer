<?php

namespace ftIndex\fst;

// TODO: merge with PagedBytes, except PagedBytes doesn't
// let you read while writing which FST needs

use ftIndex\analyses\hunspell\BytesReader;
use ftIndex\analyses\hunspell\ReverseBytesReader;
use ftIndex\store\DataInput;
use ftIndex\store\DataOutput;

class BytesStore extends DataOutput
{

    const BASE_RAM_BYTES_USED = 48;

    public $blocks = [];
    public $blocksPos = 0;

    public $blockSize;
    public $blockBits;
    public $blockMask;

    public $current;
    public $nextWrite;

    public function __construct()
    {

    }

    public static function constructByBlockBits($blockBits)
    {
        $byteStore = new self();

        $byteStore->blockBits = $blockBits;
        $byteStore->blockSize = 1 << $blockBits;
        $byteStore->blockMask = $byteStore->blockSize - 1;
        $byteStore->nextWrite = $byteStore->blockSize;

        return $byteStore;
    }

    /** Pulls bytes from the provided IndexInput.  */
    public static function constructByDataInput(DataInput $in, $numBytes, $maxBlockSize)
    {
        $byteStore = new self();

        $blockSize = 2;
        $blockBits = 1;
        while ($blockSize < $numBytes && $blockSize < $maxBlockSize) {
            $blockSize *= 2;
            $blockBits++;
        }
        $byteStore->blockBits = $blockBits;
        $byteStore->blockSize = $blockSize;
        $byteStore->blockMask = $blockSize - 1;
        $left = $numBytes;
        while ($left > 0) {
            $chunk = min($blockSize, $left);
            $block = []; //new byte[chunk];
            $in->readBytes($block, 0, $chunk);
            $byteStore->blocks[$byteStore->blocksPos++] = $block;
            $left -= $chunk;
        }

        // So .getPosition still works
        $byteStore->nextWrite = count($byteStore->blocks[count($byteStore->blocks) - 1]);

        return $byteStore;
    }

    /** Absolute write byte; you must ensure dest is &lt; max
     *  position written so far. */
//  public function writeByte($dest, $b) {
//    $blockIndex = $dest >> $this->blockBits;
//    $block = $this->blocks[$blockIndex];
//    $block[$dest & $this->blockMask] = $b;
//  }

    public function writeByte($b)
    {
        if ($this->nextWrite == $this->blockSize) {
            $this->blocks[$this->blocksPos++] = [];
            unset($this->current);
            $this->current = &$this->blocks[$this->blocksPos - 1];
            $this->nextWrite = 0;
        }
        $this->current[$this->nextWrite++] = $b;
    }

    public function writeBytes($b, $offset, $len = null)
    {
        if (is_string($b)) {
            $b = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
        }
        if ($len === null) {
            $len = count($b);
        }
        while ($len > 0) {
            $chunk = $this->blockSize - $this->nextWrite;
            if ($len <= $chunk) {
//        assert b != null;
                if ($b === null) {
                    throw new \AssertionError('NOT: b != null');
                }
//        assert current != null;
                if ($this->current === null) {
                    throw new \AssertionError('NOT: current != null');
                }
//        System.arraycopy(b, offset, current, nextWrite, len);
                $this->current = array_merge($this->current, array_slice($b, $offset, $len));
                $this->nextWrite += $len;
                break;
            } else {
                if ($chunk > 0) {
//          System.arraycopy(b, offset, current, nextWrite, chunk);

                    $this->current = array_merge($this->current, array_slice($b, $offset, $chunk));
                    $offset += $chunk;
                    $len -= $chunk;
                }
                $this->blocks[$this->blocksPos++] = [];
                unset($this->current);
                $this->current = &$this->blocks[$this->blocksPos - 1];
                $this->nextWrite = 0;
            }
        }
    }

    public function getBlockBits()
    {
        return $this->blockBits;
    }

    /** Absolute writeBytes without changing the current
     *  position.  Note: this cannot "grow" the bytes, so you
     *  must only call it on already written parts. */
  public function writeBytesByDestination($dest, $b, $offset, $len) {
    error_log("  BS.writeBytes dest=" . $dest . " offset=" . $offset . " len=" . $len);
//    assert dest + len <= getPosition(): "dest=" + dest + " pos=" + getPosition() + " len=" + len;
      if ($dest + $len > $this->getPosition()) {
          throw new \AssertionError("dest=" . $dest . " pos=" . $this->getPosition() . " len=" . $len);
      }

    // Note: weird: must go "backwards" because copyBytes
    // calls us with overlapping src/dest.  If we
    // go forwards then we overwrite bytes before we can
    // copy them:

    /*
    int blockIndex = dest >> blockBits;
    int upto = dest & blockMask;
    byte[] block = blocks.get(blockIndex);
    while (len > 0) {
      int chunk = blockSize - upto;
      System.out.println("    cycle chunk=" + chunk + " len=" + len);
      if (len <= chunk) {
        System.arraycopy(b, offset, block, upto, len);
        break;
      } else {
        System.arraycopy(b, offset, block, upto, chunk);
        offset += chunk;
        len -= chunk;
        blockIndex++;
        block = blocks.get(blockIndex);
        upto = 0;
      }
    }
    */

    $end = $dest + $len;
    $blockIndex = (int) ($end >> $this->blockBits);
    $downTo = (int) ($end & $this->blockMask);
    if ($downTo == 0) {
      $blockIndex--;
      $downTo = $this->blockSize;
    }
    $block = &$this->blocks[$blockIndex];

    while ($len > 0) {
      //System.out.println("    cycle downTo=" + downTo + " len=" + len);
      if ($len <= $downTo) {
        error_log("      final: offset=" . $offset . " len=" . $len . " dest=" . ($downTo-$len));
        Util::arraycopy($b, $offset, $block, $downTo-$len, $len);
        break;
      } else {
        $len -= $downTo;
        error_log("      partial: offset=" . ($offset + $len) . " len=" . $downTo . " dest=0");
        Util::arraycopy($b, $offset + $len, $block, 0, $downTo);
        $blockIndex--;
        unset($block);
        $block = &$this->blocks[$blockIndex];
        $downTo = $this->blockSize;
      }
    }
  }

    /** Absolute copy bytes self to self, without changing the
     *  position. Note: this cannot "grow" the bytes, so must
     *  only call it on already written parts. */
    public function copyBytes($src, $dest, $len = null)
    {
        error_log("BS.copyBytes src=" . $src . " dest=" . $dest . " len=" . $len);
//    assert src < dest;
        if ($src >= $dest) {
            throw new \AssertionError('NOT: src < dest');
        }

        // Note: weird: must go "backwards" because copyBytes
        // calls us with overlapping src/dest.  If we
        // go forwards then we overwrite bytes before we can
        // copy them:

        /*
        int blockIndex = src >> blockBits;
        int upto = src & blockMask;
        byte[] block = blocks.get(blockIndex);
        while (len > 0) {
          int chunk = blockSize - upto;
          System.out.println("  cycle: chunk=" + chunk + " len=" + len);
          if (len <= chunk) {
            writeBytes(dest, block, upto, len);
            break;
          } else {
            writeBytes(dest, block, upto, chunk);
            blockIndex++;
            block = blocks.get(blockIndex);
            upto = 0;
            len -= chunk;
            dest += chunk;
          }
        }
        */

        $end = $src + $len;

        $blockIndex = (int)($end >> $this->blockBits);
        $downTo = (int)($end & $this->blockMask);
        if ($downTo == 0) {
            $blockIndex--;
            $downTo = $this->blockSize;
        }
        $block = $this->blocks[$blockIndex];

        while ($len > 0) {
            //System.out.println("  cycle downTo=" + downTo);
            if ($len <= $downTo) {
                //System.out.println("    finish");
                $this->writeBytesByDestination($dest, $block, $downTo - $len, $len);
                break;
            } else {
                //System.out.println("    partial");
                $len -= $downTo;
                $this->writeBytesByDestination($dest + $len, $block, 0, $downTo);
                $blockIndex--;
                $block = $this->blocks[$blockIndex];
                $downTo = $this->blockSize;
            }
        }
    }

    /** Writes an int at the absolute position without
     *  changing the current pointer. */
    public function writeInt($pos, $value)
    {
        $blockIndex = (int)($pos >> $this->blockBits);
        $upto = (int)($pos & $this->blockMask);
        $block = $this->blocks[$blockIndex];
        $shift = 24;
        for ($i = 0; $i < 4; $i++) {
            $block[$upto++] = ($value >> $shift);
            $shift -= 8;
            if ($upto == $this->blockSize) {
                $upto = 0;
                $blockIndex++;
                $block = $this->blocks[$blockIndex];
            }
        }
    }
//
//  /** Reverse from srcPos, inclusive, to destPos, inclusive. */
    public function reverse($srcPos, $destPos)
    {
//    assert srcPos < destPos;
        if ($srcPos >= $destPos) {
            throw new \AssertionError('srcPos < destPos');
        }
//    assert destPos < getPosition();
        if ($destPos >= $this->getPosition()) {
            throw new \AssertionError('destPos < getPosition()');
        }
//        error_log("reverse src=" . $srcPos . " dest=" . $destPos);

//        var_dump($this->blocks);die();

        $srcBlockIndex = (int)($srcPos >> $this->blockBits);
        $src = (int)($srcPos & $this->blockMask);
        $srcBlock = $this->blocks[$srcBlockIndex];

        $destBlockIndex = (int)($destPos >> $this->blockBits);
        $dest = (int)($destPos & $this->blockMask);
        $destBlock = $this->blocks[$destBlockIndex];
        //System.out.println("  srcBlock=" + srcBlockIndex + " destBlock=" + destBlockIndex);

        $limit = (int)($destPos - $srcPos + 1) / 2;
        for ($i = 0; $i < $limit; $i++) {
//            error_log("  cycle src=" . $src . " dest=" . $dest);
            $b = $srcBlock[$src];
            $srcBlock[$src] = $destBlock[$dest];


            $destBlock[$dest] = $b;
            $src++;
            if ($src == $this->blockSize) {
                $srcBlockIndex++;
                $srcBlock = $this->blocks[$srcBlockIndex];
//                error_log("  set destBlock=" . $destBlock . " srcBlock=" . $srcBlock);
                $src = 0;
            }

            $dest--;
            if ($dest == -1) {
                $destBlockIndex--;
                $destBlock = $this->blocks[$destBlockIndex];
//                error_log("  set destBlock=" . $destBlock . " srcBlock=" . $srcBlock);
                $dest = $this->blockSize - 1;
            }
        }
    }

    public function skipBytes(int $len)
    {
        while ($len > 0) {
            $chunk = $this->blockSize - $this->nextWrite;
            if ($len <= $chunk) {
                $this->nextWrite += $len;
                break;
            } else {
                $len -= $chunk;
                $this->blocks[$this->blocksPos++] = [];
                unset($this->current);
                $this->current = &$this->blocks[$this->blocksPos - 1];
                $this->nextWrite = 0;
            }
        }
    }

//
  public function getPosition() {
    return ((int) count($this->blocks)-1) * $this->blockSize + $this->nextWrite;
  }
//
//  /** Pos must be less than the max position written so far!
//   *  Ie, you cannot "grow" the file with this! */
//  public function truncate(long newLen) {
//    assert newLen <= getPosition();
//    assert newLen >= 0;
//    int blockIndex = (int) (newLen >> blockBits);
//    nextWrite = (int) (newLen & blockMask);
//    if (nextWrite == 0) {
//      blockIndex--;
//      nextWrite = blockSize;
//    }
//    blocks.subList(blockIndex+1, blocks.size()).clear();
//    if (newLen == 0) {
//      current = null;
//    } else {
//      current = blocks.get(blockIndex);
//    }
//    assert newLen == getPosition();
//  }
//
  public function finish() {
    if ($this->current !== null) {
      $lastBuffer = [];
      Util::arraycopy($this->current, 0, $lastBuffer, 0, $this->nextWrite);
      $this->blocks[count($this->blocks)-1] = $lastBuffer;
      unset($this->current);
    }
  }
//
//  /** Writes all of our bytes to the target {@link DataOutput}. */
//  public function writeTo(DataOutput out)
//{
//    for(byte[] block : blocks) {
//      out.writeBytes(block, 0, block.length);
//    }
//  }
//
//  public FST.BytesReader getForwardReader() {
//    if (blocks.size() == 1) {
//      return new ForwardBytesReader(blocks.get(0));
//    }
//    return new FST.BytesReader() {
//      private byte[] current;
//      private int nextBuffer;
//      private int nextRead = blockSize;
//
//      @Override
//      public byte readByte() {
//        if (nextRead == blockSize) {
//          current = blocks.get(nextBuffer++);
//          nextRead = 0;
//        }
//        return current[nextRead++];
//      }
//
//      @Override
//      public function skipBytes(long count) {
//        setPosition(getPosition() + count);
//      }
//
//      @Override
//      public function readBytes(byte[] b, int offset, int len) {
//        while(len > 0) {
//          int chunkLeft = blockSize - nextRead;
//          if (len <= chunkLeft) {
//            System.arraycopy(current, nextRead, b, offset, len);
//            nextRead += len;
//            break;
//          } else {
//            if (chunkLeft > 0) {
//              System.arraycopy(current, nextRead, b, offset, chunkLeft);
//              offset += chunkLeft;
//              len -= chunkLeft;
//            }
//            current = blocks.get(nextBuffer++);
//            nextRead = 0;
//          }
//        }
//      }
//
//      @Override
//      public long getPosition() {
//        return ((long) nextBuffer-1)*blockSize + nextRead;
//      }
//
//      @Override
//      public function setPosition(long pos) {
//        int bufferIndex = (int) (pos >> blockBits);
//        nextBuffer = bufferIndex+1;
//        current = blocks.get(bufferIndex);
//        nextRead = (int) (pos & blockMask);
//        assert getPosition() == pos;
//      }
//
//      @Override
//      public boolean reversed() {
//        return false;
//      }
//    };
//  }
//
//  public FST.BytesReader getReverseReader() {
//    return getReverseReader(true);
//  }
//
    public function getReverseReader(bool $allowSingle = true)
    {
        if ($allowSingle && count($this->blocks) == 1) {
            return new ReverseBytesReader($this->blocks[0]);
        }
        return new class($this) extends BytesReader
        {
            private $current = []; //blocks.size() == 0 ? null : blocks.get(0);
            private $nextBuffer = -1;
            private $nextRead = 0;
            private $parent;

            public function __construct($parent)
            {
                $this->parent = $parent;
            }

            public function readByte()
            {
                if ($this->nextRead == -1) {
                    unset($this->current);
                    $this->current = &$this->parent->blocks[$this->nextBuffer--];
                    $this->nextRead = $this->parent->blockSize - 1;
                }
                return isset($this->current[$this->nextRead-1]) ? $this->current[$this->nextRead--] : 0;
            }

            public function skipBytes($count)
            {
                $this->setPosition($this->getPosition() - $count);
            }

            public function readBytes(&$b, $offset, $len, $useBuffer = false)
            {
                for ($i = 0; $i < $len; $i++) {
                    $b[$offset + $i] = $this->readByte();
                }
            }

            public function getPosition()
            {
                return ((int)$this->nextBuffer + 1) * $this->parent->blockSize + $this->nextRead;
            }

            public function setPosition($pos)
            {
                // NOTE: a little weird because if you
                // setPosition(0), the next byte you read is
                // bytes[0] ... but I would expect bytes[-1] (ie,
                // EOF)...?
                $bufferIndex = (int)($pos >> $this->parent->blockBits);
                $this->nextBuffer = $bufferIndex - 1;
                unset($this->current);
                $this->current = &$this->parent->blocks[$bufferIndex];
                $this->nextRead = (int)($pos & $this->parent->blockMask);
//        assert getPosition() == pos: "pos=" + pos + " getPos()=" + getPosition();
            }

            public function reversed()
            {
                return true;
            }
        };
    }
//
//  public long ramBytesUsed() {
//    long size = BASE_RAM_BYTES_USED;
//    for (byte[] block : blocks) {
//      size += RamUsageEstimator.sizeOf(block);
//    }
//    return size;
//  }
//
//  public String toString() {
//    return getClass().getSimpleName() + "(numBlocks=" + blocks.size() + ")";
//  }
}
