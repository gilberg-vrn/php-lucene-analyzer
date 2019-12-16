<?php

namespace ftIndex\store;

/** 
 * Random Access Index API.
 * Unlike {@link IndexInput}, this has no concept of file position, all reads
 * are absolute. However, like IndexInput, it is only intended for use by a single thread.
 */
interface RandomAccessInput {
  
  /** 
   * Reads a byte at the given position in the file
   * @see DataInput#readByte
   */
  public function readByte($pos);
  /** 
   * Reads a short at the given position in the file
   * @see DataInput#readShort
   */
  public function readShort($pos);
  /** 
   * Reads an integer at the given position in the file
   * @see DataInput#readInt
   */
  public function readInt($pos);
  /** 
   * Reads a long at the given position in the file
   * @see DataInput#readLong
   */
  public function readLong($pos);
}
