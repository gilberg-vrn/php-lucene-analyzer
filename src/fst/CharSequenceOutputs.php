<?php

namespace ftIndex\fst;

use ftIndex\store\DataInput;
use ftIndex\store\DataOutput;

class CharSequenceOutputs extends Outputs
{

    private $NO_OUTPUT = [];
    private static $singleton;

    protected function __construct()
    {
    }

    public static function getSingleton()
    {
        if (self::$singleton === null) {
            self::$singleton = new static();
        }
        return self::$singleton;
    }

    public function common($output1, $output2)
    {
//assert output1 != null;
        if ($output1 == null) {
            throw new \AssertionError('NOT: output1 != null');
        }
//assert output2 != null;
        if ($output2 == null) {
            throw new \AssertionError('NOT: output2 != null');
        }

        $pos1 = 0; //$output1.offset;
        $pos2 = 0; //output2.offset;
        $stopAt1 = $pos1 + min(count($output1), count($output2));
        while ($pos1 < $stopAt1) {
            if ($output1[$pos1] != $output2[$pos2]) {
                break;
            }
            $pos1++;
            $pos2++;
        }

        if ($pos1 == $output1) {
            // no common prefix
            return $this->NO_OUTPUT;
        } elseif ($pos1 == count($output1)) {
            // output1 is a prefix of output2
            return $output1;
        } elseif ($pos2 == count($output2)) {
            // output2 is a prefix of output1
            return $output2;
        } else {
            return array_slice($output1, 0, $pos1);
        }
    }

    public function subtract($output, $inc)
    {

//assert output != null;
        if ($output == null) {
            throw new \AssertionError('NOT: output != null');
        }
//assert inc != null;
        if ($inc == null) {
            throw new \AssertionError('NOT: inc != null');
        }
        if ($inc == $this->NO_OUTPUT) {
            // no prefix removed
            return $output;
        } elseif (count($inc) == count($output)) {
            // entire output removed
            return $this->NO_OUTPUT;
        } else {
//      assert inc.length < output.length: "inc.length=" + inc.length + " vs output.length=" + output.length;
            if (count($inc) > count($output)) {
                throw new \AssertionError("inc.length=" . count($inc) . " vs output.length=" . count($output));
            }
//      assert inc.length > 0;
            if (count($inc) > 0) {
                throw new \AssertionError('NOT: inc.length < 0');
            }
            return array_slice($output, count($inc), count($output) - count($inc));
        }
    }

    public function add($prefix, $output)
    {
//assert prefix != null;
        if ($prefix == null) {
            throw new \AssertionError('NOT: prefix != null');
        }
//assert output != null;
        if ($output == null) {
            throw new \AssertionError('NOT: output != null');
        }
        if ($prefix == $this->NO_OUTPUT) {
            return $output;
        } elseif ($output == $this->NO_OUTPUT) {
            return $prefix;
        } else {
//      assert prefix.length > 0;
            if (count($prefix) <= 0) {
                throw new \AssertionError('NOT: prefix.length > 0');
            }
//      assert output.length > 0;
            if (count($output) <= 0) {
                throw new \AssertionError('NOT: output.length > 0');
            }


            $result = []; //new IntsRef(prefix.length + output.length);
//      System.arraycopy(prefix.ints, prefix.offset, result.ints, 0, prefix.length);
            $result = array_slice($prefix, 0, count($prefix));
//      System.arraycopy(output.ints, output.offset, result.ints, prefix.length, output.length);
            $result = array_merge($result, array_slice($output, 0, count($output)));

            return $result;
        }
    }

    public function write($prefix, DataOutput $out)
    {
//    assert prefix != null;
        if ($prefix == null) {
            throw new \AssertionError('NOT: prefix != null');
        }
        $out->writeVInt(count($prefix));
        for ($idx = 0; $idx < count($prefix); $idx++) {
            $out->writeVInt(\IntlChar::ord($prefix[$idx]));
        }
    }

    public function read(DataInput $in)
    {
        $len = $in->readVInt();
        if ($len == 0) {
            return $this->NO_OUTPUT;
        } else {
            $output = [];
            for ($idx = 0; $idx < $len; $idx++) {
                $output[$idx] = $in->readVInt();
            }

            return $output;
        }
    }

    public function skipOutput(DataInput $in)
    {
        $len = $in->readVInt();
        if ($len == 0) {
            return;
        }
        for ($idx = 0; $idx < $len; $idx++) {
            $in->readVInt();
        }
    }

    public function getNoOutput()
    {
        return $this->NO_OUTPUT;
    }

    public function outputToString($output)
    {
        /** @var static $output */
        return '[ ' . implode(', ', $output) . ' ]';
    }

    const BASE_NUM_BYTES = 1;

    public function ramBytesUsed($output)
    {
        return self::BASE_NUM_BYTES + sizeOf($output);
    }

    public function __toString()
    {
        return "CharSequenceOutputs";
    }
}
