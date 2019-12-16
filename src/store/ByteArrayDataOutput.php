<?php

namespace ftIndex\store;

/**
 * Class ByteArrayDataOutput
 *
 * @package ftIndex\store
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/16/19 7:41 PM
 */
class ByteArrayDataOutput extends DataOutput {
    private $bytes = [];

  private $pos;
  private $limit;

  public function __construct(&$bytes = [], $offset = 0, $len = null) {
    $this->reset($bytes, $offset, $len);
  }

  public function reset(&$bytes, $offset = 0, $len = null) {
      if ($len === null) {
          $len = count($bytes);
      }
      $this->bytes = &$bytes;
      $this->pos = $offset;
      $this->limit = $offset + $len;
}

  public function getPosition() {
    return $this->pos;
  }

  public function writeByte($b) {
//    assert pos < limit;
    if ($this->pos >= $this->limit) {
        throw new \AssertionError("pos < limit: {$this->pos} < {$this->limit}");
    }

    $this->bytes[$this->pos++] = $b;
  }

  public function writeBytes($b, $offset, $length = null) {
      if ($length === null) {
          $length = count($b);
      }
//        assert pos + length <= limit;
      if (($this->pos + $length) > $this->limit) {
          throw new \AssertionError('pos + length <= limit');
      }
      for ($i = 0; $i < $length; $i++) {
          $this->bytes[$this->pos++] = $b[$i + $offset];
      }
  }
}
