<?php

namespace ftIndex\store;

use ftIndex\analyses\hunspell\IllegalArgumentException;

abstract class IndexInput extends DataInput
{

  private $resourceDescription;

  /** resourceDescription should be a non-null, opaque string
   *  describing this resource; it's returned from
   *  {@link #toString}. */
  protected function IndexInput($resourceDescription) {
    if ($resourceDescription == null) {
      throw new IllegalArgumentException("resourceDescription must not be null");
    }
    $this->resourceDescription = $resourceDescription;
  }

  public abstract function close();

  /** Returns the current position in this file, where the next read will
   * occur.
   * @see #seek(long)
   */
  public abstract function getFilePointer();

  /** Sets current position in this file, where the next read will occur.  If this is
   *  beyond the end of the file then this will throw {@code EOFException} and then the
   *  stream is in an undetermined state.
   *
   * @see #getFilePointer()
   */
  public abstract function seek($pos);

  /** The number of bytes in the file. */
  public abstract function length();

  public function __toString() {
    return $this->resourceDescription;
  }
  
  /** {@inheritDoc}
   * 
   * <p><b>Warning:</b> Lucene never closes cloned
   * {@code IndexInput}s, it will only call {@link #close()} on the original object.
   * 
   * <p>If you access the cloned IndexInput after closing the original object,
   * any <code>readXXX</code> methods will throw {@link AlreadyClosedException}.
   *
   * <p>This method is NOT thread safe, so if the current {@code IndexInput}
   * is being used by one thread while {@code clone} is called by another,
   * disaster could strike.
   */
  public function clone() {
    return clone $this;
  }
  
  /**
   * Creates a slice of this index input, with the given description, offset, and length. 
   * The slice is sought to the beginning.
   */
  public abstract function slice($sliceDescription, $offset, $length);

  /** Subclasses call this to get the String for resourceDescription of a slice of this {@code IndexInput}. */
  protected function getFullSliceDescription($sliceDescription) {
    if ($sliceDescription == null) {
      // Clones pass null sliceDescription:
      return $this->__toString();
    } else {
      return "{$this->__toString()} [slice={$sliceDescription}]";
    }
  }

  /**
   * Creates a random-access slice of this index input, with the given offset and length. 
   * <p>
   * The default implementation calls {@link #slice}, and it doesn't support random access,
   * it implements absolute reads as seek+read.
   */
  public function randomAccessSlice($offset, $length) {
    $slice = $this->slice("randomaccess", $offset, $length);
    if ($slice instanceof RandomAccessInput) {
      // slice() already supports random access
      return $slice;
    } else {
      // return default impl
      return new class($slice) implements RandomAccessInput {

          /** @var IndexInput */
          protected $slice;
          public function __construct($slice)
          {
              $this->slice = $slice;
          }

        public function readByte($pos) {
          $this->slice->seek($pos);
          return $this->slice->readByte();
        }

        public function readShort($pos) {
          $this->slice->seek($pos);
          return $this->slice->readShort();
        }

        public function readInt($pos) {
          $this->slice->seek($pos);
          return $this->slice->readInt();
        }

        public function readLong($pos) {
          $this->slice->seek($pos);
          return $this->slice->readLong();
        }

        public function __toString() {
          return "RandomAccessInput(" . $this->__toString() . ")";
        }
      };
    }
  }
}
