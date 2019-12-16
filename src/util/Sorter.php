<?php

namespace ftIndex\util;


/** Base class for sorting algorithms implementations.
 * @lucene.internal
 */
abstract class Sorter
{

    const BINARY_SORT_THRESHOLD = 20;

    /** Sole constructor, used for inheritance. */
    protected function __construct()
    {
    }

    /** Compare entries found in slots <code>i</code> and <code>j</code>.
     *  The contract for the returned value is the same as
     *  {@link Comparator#compare(Object, Object)}. */
    protected abstract function compare(int $i, int $j): int;

    /** Swap values at slots <code>i</code> and <code>j</code>. */
    protected abstract function swap(int $i, int $j);

    /** @var int */
    private $pivotIndex;

    /** Save the value at slot <code>i</code> so that it can later be used as a
     * pivot, see {@link #comparePivot(int)}. */
    protected function setPivot(int $i)
    {
        $this->pivotIndex = $i;
    }

    /** Compare the pivot with the slot at <code>j</code>, similarly to
     *  {@link #compare(int, int) compare(i, j)}. */
    protected function comparePivot(int $j): int
    {
        return $this->compare($this->pivotIndex, $j);
    }

    /** Sort the slice which starts at <code>from</code> (inclusive) and ends at
     *  <code>to</code> (exclusive). */
    public abstract function sort(int $from, int $to);

    public function checkRange(int $from, int $to)
    {
        if ($to < $from) {
            throw new IllegalArgumentException("'to' must be >= 'from', got from={$from} and to={$to}");
        }
    }

    public function mergeInPlace(int $from, int $mid, int $to)
    {
        if ($from == $mid || $mid == $to || $this->compare($mid - 1, $mid) <= 0) {
            return;
        } else {
            if ($to - $from == 2) {
                $this->swap($mid - 1, $mid);
                return;
            }
        }
        while ($this->compare($from, $mid) <= 0) {
            ++$from;
        }
        while ($this->compare($mid - 1, $to - 1) <= 0) {
            --$to;
        }
        if ($mid - $from > $to - $mid) {
            $len11 = ($mid - $from);
            $len11 = BitUtil::uRShift($len11, 1);
            $first_cut = $from + $len11;
            $second_cut = $this->lower($mid, $to, $first_cut);
            $len22 = $second_cut - $mid;
        } else {
            $len22 = ($to - $mid);
            $len22 = BitUtil::uRShift($len22, 1);
            $second_cut = $mid + $len22;
            $first_cut = $this->upper($from, $mid, $second_cut);
            $len11 = $first_cut - $from;
        }
        $this->rotate($first_cut, $mid, $second_cut);
        $new_mid = $first_cut + $len22;
        $this->mergeInPlace($from, $first_cut, $new_mid);
        $this->mergeInPlace($new_mid, $second_cut, $to);
    }

    public function lower(int $from, int $to, int $val): int
    {
        $len = $to - $from;
        while ($len > 0) {
            $half = $len;
            $half = BitUtil::uRShift($half, 1);
            $mid = $from + $half;
            if ($this->compare($mid, $val) < 0) {
                $from = $mid + 1;
                $len = $len - $half - 1;
            } else {
                $len = $half;
            }
        }
        return $from;
    }

    public function upper(int $from, int $to, int $val): int
    {
        $len = $to - $from;
        while ($len > 0) {
            $half = $len;
            $half = BitUtil::uRShift($half, 1);
            $mid = $from + $half;
            if ($this->compare($val, $mid) < 0) {
                $len = $half;
            } else {
                $from = $mid + 1;
                $len = $len - $half - 1;
            }
        }
        return $from;
    }

    // faster than lower when val is at the end of [from:to[
    public function lower2(int $from, int $to, int $val): int
    {
        $f = $to - 1;
        $t = $to;
        while ($f > $from) {
            if ($this->compare($f, $val) < 0) {
                return $this->lower($f, $t, $val);
            }
            $delta = $t - $f;
            $t = $f;
            $f -= $delta << 1;
        }
        return $this->lower($from, $t, $val);
    }

    // faster than upper when val is at the beginning of [from:to[
    public function upper2(int $from, int $to, int $val): int
    {
        $f = $from;
        $t = $f + 1;
        while ($t < $to) {
            if ($this->compare($t, $val) > 0) {
                return $this->upper($f, $t, $val);
            }
            $delta = $t - $f;
            $f = $t;
            $t += $delta << 1;
        }
        return $this->upper($f, $to, $val);
    }

    final public function reverse(int $from, int $to)
    {
        for (--$to; $from < $to; ++$from, --$to) {
            $this->swap($from, $to);
        }
    }

    final public function rotate(int $lo, int $mid, int $hi)
    {
//    assert lo <= mid && mid <= hi;
        if ($lo == $mid || $mid == $hi) {
            return;
        }
        $this->doRotate($lo, $mid, $hi);
    }

    public function doRotate(int $lo, int $mid, int $hi)
    {
        if ($mid - $lo == $hi - $mid) {
            // happens rarely but saves n/2 swaps
            while ($mid < $hi) {
                $this->swap($lo++, $mid++);
            }
        } else {
            $this->reverse($lo, $mid);
            $this->reverse($mid, $hi);
            $this->reverse($lo, $hi);
        }
    }

    /**
     * A binary sort implementation. This performs {@code O(n*log(n))} comparisons
     * and {@code O(n^2)} swaps. It is typically used by more sophisticated
     * implementations as a fall-back when the numbers of items to sort has become
     * less than {@value #BINARY_SORT_THRESHOLD}.
     */

    public function binarySort(int $from, int $to, int $i = null)
    {
        if ($i === null) {
            $i = $from + 1;
        }
        for (; $i < $to; ++$i) {
            $this->setPivot($i);
            $l = $from;
            $h = $i - 1;
            while ($l <= $h) {
                $mid = ($l + $h);
                $mid = BitUtil::uRShift($mid, 1);
                $cmp = $this->comparePivot($mid);
                if ($cmp < 0) {
                    $h = $mid - 1;
                } else {
                    $l = $mid + 1;
                }
            }
            for ($j = $i; $j > $l; --$j) {
                $this->swap($j - 1, $j);
            }
        }
    }

    /**
     * Use heap sort to sort items between {@code from} inclusive and {@code to}
     * exclusive. This runs in {@code O(n*log(n))} and is used as a fall-back by
     * {@link IntroSorter}.
     */
    function heapSort(int $from, int $to)
    {
        if ($to - $from <= 1) {
            return;
        }
        $this->heapify($from, $to);
        for ($end = $to - 1; $end > $from; --$end) {
            $this->swap($from, $end);
            $this->siftDown($from, $from, $end);
        }
    }

    function heapify(int $from, int $to)
    {
        for ($i = $this->heapParent($from, $to - 1); $i >= $from; --$i) {
            $this->siftDown($i, $from, $to);
        }
    }

    function siftDown(int $i, int $from, int $to)
    {
        for ($leftChild = $this->heapChild($from, $i); $leftChild < $to; $leftChild = $this->heapChild($from, $i)) {
            $rightChild = $leftChild + 1;
            if ($this->compare($i, $leftChild) < 0) {
                if ($rightChild < $to && $this->compare($leftChild, $rightChild) < 0) {
                    $this->swap($i, $rightChild);
                    $i = $rightChild;
                } else {
                    $this->swap($i, $leftChild);
                    $i = $leftChild;
                }
            } else {
                if ($rightChild < $to && $this->compare($i, $rightChild) < 0) {
                    $this->swap($i, $rightChild);
                    $i = $rightChild;
                } else {
                    break;
                }
            }
        }
    }

    static public function heapParent(int $from, int $i): int
    {
        $tmp = ($i - 1 - $from);
        $tmp = BitUtil::uRShift($tmp, 1);
        return $tmp + $from;
    }

    static public function heapChild(int $from, int $i): int
    {
        return (($i - $from) << 1) + 1 + $from;
    }

}
