<?php

namespace ftIndex\fst;

use ftIndex\store\DataInput;
use ftIndex\store\DataOutput;
use ftIndex\analyses\hunspell\UnsupportedOperationException;

abstract class Outputs {

  // TODO: maybe change this API to allow for re-use of the
  // output instances -- this is an insane amount of garbage
  // (new object per byte/char/int) if eg used during
  // analysis

  /** Eg common("foobar", "food") -&gt; "foo" */
  public abstract function common($output1, $output2);

  /** Eg subtract("foobar", "foo") -&gt; "bar" */
  public abstract function subtract($output, $inc);

  /** Eg add("foo", "bar") -&gt; "foobar" */
  public abstract function add($prefix, $output);

  /** Encode an output value into a {@link DataOutput}. */
  public abstract function write($output, DataOutput $out);

  /** Encode an final node output value into a {@link
   *  DataOutput}.  By default this just calls {@link #write(Object,
   *  DataOutput)}. */
  public function writeFinalOutput($output, DataOutput $out) {
    $this->write($output, $out);
  }

  /** Decode an output value previously written with {@link
   *  #write(Object, DataOutput)}. */
  public abstract function read(DataInput $in);

  /** Skip the output; defaults to just calling {@link #read}
   *  and discarding the result. */
  public function skipOutput(DataInput $in) {
    $this->read($in);
  }

  /** Decode an output value previously written with {@link
   *  #writeFinalOutput(Object, DataOutput)}.  By default this
   *  just calls {@link #read(DataInput)}. */
  public function readFinalOutput(DataInput $in) {
    return $this->read($in);
  }
  
  /** Skip the output previously written with {@link #writeFinalOutput};
   *  defaults to just calling {@link #readFinalOutput} and discarding
   *  the result. */
  public function skipFinalOutput(DataInput $in) {
    $this->skipOutput($in);
  }

  /** NOTE: this output is compared with == so you must
   *  ensure that all methods return the single object if
   *  it's really no output */
  public abstract function getNoOutput();

  public abstract function outputToString($output);

  // TODO: maybe make valid(T output) public...?  for asserts

  public function merge($first, $second) {
    throw new UnsupportedOperationException();
  }

  /** Return memory usage for the provided output.
   *  @see Accountable */
  public abstract function ramBytesUsed($output);
}
