<?php

namespace ftIndex\util;


/** {@link Sorter} implementation based on the merge-sort algorithm that merges
 *  in place (no extra memory will be allocated). Small arrays are sorted with
 *  insertion sort.
 * @lucene.internal
 */
abstract class InPlaceMergeSorter extends Sorter
{

    /** Create a new {@link InPlaceMergeSorter} */
    public function __construct()
    {
        parent::__construct();
    }

    public final function sort(int $from, int $to)
    {
        $this->checkRange($from, $to);
        $this->mergeSort($from, $to);
    }

    public function mergeSort(int $from, int $to)
    {
        if ($to - $from < self::BINARY_SORT_THRESHOLD) {
            $this->binarySort($from, $to);
        } else {
            $mid = ($from + $to) >> 1;
            $this->mergeSort($from, $mid);
            $this->mergeSort($mid, $to);
            $this->mergeInPlace($from, $mid, $to);
        }
    }

}
