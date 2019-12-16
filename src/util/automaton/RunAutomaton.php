<?php

namespace ftIndex\util\automaton;

use ftIndex\fst\Util;
use ftIndex\util\BitUtil;

/**
 * Class RunAutomaton
 *
 * @package ftIndex\util\automaton
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/25/19 2:41 PM
 */
abstract class RunAutomaton
{
    /** @var  Automaton */
    protected $automaton;
    /** @var  int */
    protected $alphabetSize;
    /** @var  int */
    protected $size;
    /** @var  boolean[] */
    protected $accept;
    /** @var  int[] */
    protected $transitions; // delta(state,c) = transitions[state*points.length +
                            // getCharClass(c)]
    /** @var  int[] */
    protected $points; // char interval start points
    /** @var  int[] */
    protected $classmap; // map from char number to class

    protected $initial = 0;

    /**
     * Constructs a new <code>RunAutomaton</code> from a deterministic
     * <code>Automaton</code>.
     *
     * @param Automaton $a an automaton
     * @param int $alphabetSize
     * @param int $maxDeterminizedStates maximum number of states that can be created
     *   while determinizing a
     */
    protected function __construct(Automaton $a, int $alphabetSize, int $maxDeterminizedStates)
    {
        $this->alphabetSize = $alphabetSize;
        $a = Operations::determinize($a, $maxDeterminizedStates);
        $this->automaton = $a;
        $this->points = $a->getStartPoints();
        $size = max(1, $a->getNumStates());
        $this->accept = []; //new boolean[size];
        $this->transitions = []; //new int[size * points.length];
        $this->transitions = array_fill(0, $size * count($this->points), -1);
        for ($n = 0; $n < $size; $n++) {
            $this->accept[$n] = $a->isAccept($n);
            for ($c = 0; $c < count($this->points); $c++) {
                $dest = $a->step($n, $this->points[$c]);
//        assert dest == -1 || dest < size;
                if (!($dest == -1 || $dest < $size)) {
                    throw new \AssertionError('NOT: (dest == -1 || dest < size)');
                }
                $this->transitions[$n * count($this->points) + $c] = $dest;
            }
        }

        /*
         * Set alphabet table for optimal run performance.
         */
        $this->classmap = []; //new int[Math.min(256, alphabetSize)];
        $classMapLength = min(256, $this->alphabetSize);
        $i = 0;
        for ($j = 0; $j < $classMapLength; $j++) {
            if ($i + 1 < count($this->points) && $j == $this->points[$i + 1]) {
                $i++;
            }
            $this->classmap[$j] = $i;
        }
    }

    /**
     * Returns a string representation of this automaton.
     */
//  public String __toString() {
//StringBuilder b = new StringBuilder();
//    b.append("initial state: 0\n");
//    for (int i = 0; i < size; i++) {
//    b.append("state " + i);
//    if (accept[i]) b.append(" [accept]:\n");
//    else b.append(" [reject]:\n");
//    for (int j = 0; j < points.length; j++) {
//        int k = transitions[i * points.length + j];
//        if (k != -1) {
//            int min = points[j];
//          int max;
//          if (j + 1 < points.length) max = (points[j + 1] - 1);
//          else max = alphabetSize;
//          b.append(" ");
//          Automaton.appendCharString(min, b);
//          if (min != max) {
//              b.append("-");
//              Automaton.appendCharString(max, b);
//          }
//          b.append(" -> ").append(k).append("\n");
//        }
//      }
//    }
//    return b.toString();
//  }

    /**
     * Returns number of states in automaton.
     */
    public final function getSize(): int
    {
        return $this->size;
    }

    /**
     * Returns acceptance status for given state.
     */
    public final function isAccept(int $state): bool
    {
        return $this->accept[$state];
    }

    /**
     * Returns array of codepoint class interval start points. The array should
     * not be modified by the caller.
     */
    public final function getCharIntervals()
    {
        return $this->points;
    }

    /**
     * Gets character class of given codepoint
     */
    final public function getCharClass(int $c): int
    {

        // binary search
        $a = 0;
        $b = count($this->points);
        while ($b - $a > 1) {
            $mid = ($a + $b);
            $d = BitUtil::uRShift($mid, 1);
            if ($this->points[$d] > $c) {
                $b = $d;
            } else {
                if ($this->points[$d] < $c) {
                    $a = $d;
                } else {
                    return $d;
                }
            }
        }
        return $a;
    }

    /**
     * Returns the state obtained by reading the given char from the given state.
     * Returns -1 if not obtaining any such state. (If the original
     * <code>Automaton</code> had no dead states, -1 is returned here if and only
     * if a dead state is entered in an equivalent automaton with a total
     * transition function.)
     */
    public final function step(int $state, int $c): int
    {
//    assert $c < $this->alphabetSize;
        if (!($c < $this->alphabetSize)) {
            throw new \AssertionError('NOT: ($c < $this->alphabetSize)');
        }
        if ($c >= count($this->classmap)) {
            return $this->transitions[$state * count($this->points) + $this->getCharClass($c)];
        } else {
            return $this->transitions[$state * count($this->points) + $this->classmap[$c]];
        }
    }

    public function hashCode(): int
    {
        $prime = 31;
        $result = 1;
        $result = $prime * $result + $this->alphabetSize;
        $result = $prime * $result + count($this->points);
        $result = $prime * $result + $this->size;
        return $result;
    }

    public function equals(RunAutomaton $obj): bool
    {
        if ($this == $obj) {
            return true;
        }
        if ($obj == null) {
            return false;
        }
        if (get_class($this) != get_class($obj)) {
            return false;
        }
        $other = $obj;
        if ($this->alphabetSize != $other->alphabetSize) {
            return false;
        }
        if ($this->size != $other->size) {
            return false;
        }
        if (!Util::equals($this->points, $other->points)) {
            return false;
        }
        if (!Util::equals($this->accept, $other->accept)) {
            return false;
        }
        if (!Util::equals($this->transitions, $other->transitions)) {
            return false;
        }
        return true;
    }
}
