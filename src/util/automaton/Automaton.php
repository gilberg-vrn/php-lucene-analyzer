<?php

namespace ftIndex\util\automaton;

use ftIndex\analyses\hunspell\IllegalArgumentException;
use ftIndex\analyses\hunspell\IllegalStateException;
use ftIndex\fst\Util;
use ftIndex\util\InPlaceMergeSorter;

/**
 * Class Automaton
 *
 * @package ftIndex\util\automaton
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/24/19 12:08 PM
 *
 * @deprecated Not worked in PHP. Replaced with preg_match(pattern$)
 */
class Automaton
{
    private $nextState = 0;
    private $nextTransition = 0;
    private $curState;
    public $states = [];
    /** @var BitSet */
    private $isAccept;
    public $transitions = [];
    private $deterministic;
    /** @var Sorter */
    private $destMinMaxSorter;
    /** @var Sorter */
    private $minMaxDestSorter;

    public function __construct($numStates = 2, $numTransitions = 2)
    {
        $this->curState = -1;
        $this->deterministic = true;

        $this->destMinMaxSorter = new class($this) extends InPlaceMergeSorter
        {
            /** @var Automaton */
            protected $parent;

            public function __construct($parent)
            {
                parent::__construct();
                $this->parent = $parent;
            }

            private function swapOne(int $i, int $j)
            {
                $x = $this->parent->transitions[$i];
                $this->parent->transitions[$i] = $this->parent->transitions[$j];
                $this->parent->transitions[$j] = $x;
            }

            protected function swap(int $i, int $j)
            {
                $iStart = 3 * $i;
                $jStart = 3 * $j;
                $this->swapOne($iStart, $jStart);
                $this->swapOne($iStart + 1, $jStart + 1);
                $this->swapOne($iStart + 2, $jStart + 2);
            }

            protected function compare(int $i, int $j): int
            {
                $iStart = 3 * $i;
                $jStart = 3 * $j;
                $iDest = $this->parent->transitions[$iStart];
                $jDest = $this->parent->transitions[$jStart];
                if ($iDest < $jDest) {
                    return -1;
                } else {
                    if ($iDest > $jDest) {
                        return 1;
                    } else {
                        $iMin = $this->parent->transitions[$iStart + 1];
                        $jMin = $this->parent->transitions[$jStart + 1];
                        if ($iMin < $jMin) {
                            return -1;
                        } else {
                            if ($iMin > $jMin) {
                                return 1;
                            } else {
                                $iMax = $this->parent->transitions[$iStart + 2];
                                $jMax = $this->parent->transitions[$jStart + 2];
                                if ($iMax < $jMax) {
                                    return -1;
                                } else {
                                    return $iMax > $jMax ? 1 : 0;
                                }
                            }
                        }
                    }
                }
            }
        };

        $this->minMaxDestSorter = new class($this) extends InPlaceMergeSorter
        {
            /** @var Automaton */
            protected $parent;

            public function __construct($parent)
            {
                parent::__construct();
                $this->parent = $parent;
            }

            private function swapOne(int $i, int $j)
            {
                $x = $this->parent->transitions[$i];
                $this->parent->transitions[$i] = $this->parent->transitions[$j];
                $this->parent->transitions[$j] = $x;
            }

            protected function swap(int $i, int $j)
            {
                $iStart = 3 * $i;
                $jStart = 3 * $j;
                $this->swapOne($iStart, $jStart);
                $this->swapOne($iStart + 1, $jStart + 1);
                $this->swapOne($iStart + 2, $jStart + 2);
            }

            protected function compare(int $i, int $j): int
            {
                $iStart = 3 * $i;
                $jStart = 3 * $j;
                $iMin = $this->parent->transitions[$iStart + 1];
                $jMin = $this->parent->transitions[$jStart + 1];
                if ($iMin < $jMin) {
                    return -1;
                } else {
                    if ($iMin > $jMin) {
                        return 1;
                    } else {
                        $iMax = $this->parent->transitions[$iStart + 2];
                        $jMax = $this->parent->transitions[$jStart + 2];
                        if ($iMax < $jMax) {
                            return -1;
                        } else {
                            if ($iMax > $jMax) {
                                return 1;
                            } else {
                                $iDest = $this->parent->transitions[$iStart];
                                $jDest = $this->parent->transitions[$jStart];
                                if ($iDest < $jDest) {
                                    return -1;
                                } else {
                                    return $iDest > $jDest ? 1 : 0;
                                }
                            }
                        }
                    }
                }
            }
        };
        $this->states = array_fill(0, $numStates * 2, 0); //new int[numStates * 2];
        $this->isAccept = new BitSet($numStates);
        $this->transitions = []; //new int[numTransitions * 3];
    }

    public function createState(): int
    {
        $this->growStates();
        $state = $this->nextState / 2;
        $this->states[$this->nextState] = -1;
        $this->nextState += 2;

        return $state;
    }

    public function setAccept(int $state, bool $accept)
    {
        if ($state >= $this->getNumStates()) {
            throw new IllegalArgumentException("state=" . $state . " is out of bounds (numStates=" . $this->getNumStates() . ")");
        } else {
            if ($accept) {
                $this->isAccept->set($state);
            } else {
                $this->isAccept->clear($state);
            }

        }
    }

    public function getSortedTransitions(): array
    {
        $numStates = $this->getNumStates();
        /** @var Transition[][] $transitions */
        $transitions = []; //new Transition[numStates][];

        for ($s = 0; $s < $numStates; ++$s) {
            $numTransitions = $this->getNumTransitions($s);
            $transitions[$s] = []; //new Transition[numTransitions];

            for ($t = 0; $t < $numTransitions; ++$t) {
                $transition = new Transition();
                $this->getTransition($s, $t, $transition);
                $transitions[$s][$t] = $transition;
            }
        }

        return $transitions;
    }

    public function getAcceptStates(): BitSet
    {
        return $this->isAccept;
    }

    public function isAccept(int $state)
    {
        return $this->isAccept->get($state);
    }

    public function addTransition(int $source, int $dest, int $minLabel, int $maxLabel = null) {
        if ($maxLabel === null) {
            $maxLabel = $minLabel;
        }
//        assert $this->nextTransition % 3 == 0;
        if (!($this->nextTransition % 3 == 0)) {
            throw new \AssertionError('NOT: ($this->nextTransition % 3 == 0)');
        }

        if ($source >= $this->nextState / 2) {
            throw new IllegalArgumentException("source=" . $source . " is out of bounds (maxState is " . ($this->nextState / 2 - 1) . ")");
        }
        if ($dest >= $this->nextState / 2) {
            throw new IllegalArgumentException("dest=" . $dest . " is out of bounds (max state is " . ($this->nextState / 2 - 1) . ")");
        }

        $this->growTransitions();
        if ($this->curState != $source) {
            if ($this->curState != -1) {
                $this->finishCurrentState();
            }

            $this->curState = $source;
            if ($this->states[2 * $this->curState] != -1) {
                throw new IllegalStateException("from state (" . $source . ") already had transitions added");
            }

//                assert $this->states[2 * $this->curState + 1] == 0;
            if (!($this->states[2 * $this->curState + 1] == 0)) {
                throw new \AssertionError('NOT: ($this->states[2 * $this->curState + 1] == 0)');
            }

            $this->states[2 * $this->curState] = $this->nextTransition;
        }

        $this->transitions[$this->nextTransition++] = $dest;
        $this->transitions[$this->nextTransition++] = $minLabel;
        $this->transitions[$this->nextTransition++] = $maxLabel;

        // Increment transition count for this state
        $this->states[2*$this->curState+1]++;
    }

    public function addEpsilon(int $source, int $dest)
    {
        $t = new Transition();
        $count = $this->initTransition($dest, $t);

        for ($i = 0; $i < $count; ++$i) {
            $this->getNextTransition($t);
            $this->addTransition($source, $t->dest, $t->min, $t->max);
        }

        if ($this->isAccept($dest)) {
            $this->setAccept($source, true);
        }

    }

    /** Copies over all states/transitions from $other->  The states numbers
     *  are sequentially assigned (appended). */
    public function copy(Automaton $other)
    {

        // Bulk copy and then fixup the state pointers:
        $stateOffset = $this->getNumStates();
        $this->states = Util::growIntArray($this->states, $this->nextState + $other->nextState);
        Util::arraycopy($other->states, 0, $this->states, $this->nextState, $other->nextState);
        for ($i = 0; $i < $other->nextState; $i += 2) {
            if ($this->states[$this->nextState + $i] != -1) {
                $this->states[$this->nextState + $i] += $this->nextTransition;
            }
        }
        $this->nextState += $other->nextState;
        $otherNumStates = $other->getNumStates();
        $otherAcceptStates = $other->getAcceptStates();
        $state = 0;
        while ($state < $otherNumStates && ($state = $otherAcceptStates->nextSetBit($state)) != -1) {
            $this->setAccept($stateOffset + $state, true);
            $state++;
        }

        // Bulk copy and then fixup dest for each transition:
        $this->transitions = Util::growIntArray($this->transitions, $this->nextTransition + $other->nextTransition);
        Util::arraycopy($other->transitions, 0, $this->transitions, $this->nextTransition, $other->nextTransition);
        for ($i = 0; $i < $other->nextTransition; $i += 3) {
            $this->transitions[$this->nextTransition + $i] += $stateOffset;
        }
        $this->nextTransition += $other->nextTransition;

        if ($other->deterministic == false) {
            $this->deterministic = false;
        }
    }

    /** Freezes the last state, sorting and reducing the transitions. */
    private function finishCurrentState()
    {
        $numTransitions = $this->states[2 * $this->curState + 1];
//    assert numTransitions > 0;
        if (!($numTransitions > 0)) {
            throw new \AssertionError('NOT: (numTransitions > 0)');
        }

        $offset = $this->states[2 * $this->curState];
        $start = $offset / 3;
        $this->destMinMaxSorter->sort($start, $start + $numTransitions);

        // Reduce any "adjacent" transitions:
        $upto = 0;
        $min = -1;
        $max = -1;
        $dest = -1;

        for ($i = 0; $i < $numTransitions; $i++) {
            $tDest = $this->transitions[$offset + 3 * $i];
            $tMin = $this->transitions[$offset + 3 * $i + 1];
            $tMax = $this->transitions[$offset + 3 * $i + 2];

            if ($dest == $tDest) {
                if ($tMin <= $max + 1) {
                    if ($tMax > $max) {
                        $max = $tMax;
                    }
                } else {
                    if ($dest != -1) {
                        $this->transitions[$offset + 3 * $upto] = $dest;
                        $this->transitions[$offset + 3 * $upto + 1] = $min;
                        $this->transitions[$offset + 3 * $upto + 2] = $max;
                        $upto++;
                    }
                    $min = $tMin;
                    $max = $tMax;
                }
            } else {
                if ($dest != -1) {
                    $this->transitions[$offset + 3 * $upto] = $dest;
                    $this->transitions[$offset + 3 * $upto + 1] = $min;
                    $this->transitions[$offset + 3 * $upto + 2] = $max;
                    $upto++;
                }
                $dest = $tDest;
                $min = $tMin;
                $max = $tMax;
            }
        }

        if ($dest != -1) {
            // Last transition
            $this->transitions[$offset + 3 * $upto] = $dest;
            $this->transitions[$offset + 3 * $upto + 1] = $min;
            $this->transitions[$offset + 3 * $upto + 2] = $max;
            $upto++;
        }

        $this->nextTransition -= ($numTransitions - $upto) * 3;
        $this->states[2 * $this->curState + 1] = $upto;

        // Sort transitions by min/max/dest:
        $this->minMaxDestSorter->sort($start, $start + $upto);

        if ($this->deterministic && $upto > 1) {
            $lastMax = $this->transitions[$offset + 2];
            for ($i = 1; $i < $upto; $i++) {
                $min = $this->transitions[$offset + 3 * $i + 1];
                if ($min <= $lastMax) {
                    $this->deterministic = false;
                    break;
                }
                $lastMax = $this->transitions[$offset + 3 * $i + 2];
            }
        }
    }

    /** Returns true if this automaton is deterministic (for ever state
     *  there is only one transition for each label). */
    public function isDeterministic(): bool
    {
        return $this->deterministic;
    }

    /** Finishes the current state; call this once you are done adding
     *  transitions for a state.  This is automatically called if you
     *  start adding transitions to a new source state, but for the last
     *  state you add you need to this method yourself. */
    public function finishState()
    {
        if ($this->curState != -1) {
            $this->finishCurrentState();
            $this->curState = -1;
        }
    }

    // TODO: add finish() to shrink wrap the arrays?

    /** How many states this automaton has. */
    public function getNumStates(): int
    {
        return (int)($this->nextState / 2);
    }

    /** How many transitions this automaton or state has.
     *
     * @param int|null $state
     *
     * @return int
     */
    public function getNumTransitions(int $state = null): int
    {
        if ($state === null) {
            return (int)($this->nextTransition / 3);
        }

        //    assert state >= 0;
        if (!($state >= 0)) {
            throw new \AssertionError('NOT: (state >= 0)');
        }
        $count = $this->states[2 * $state + 1];
        if ($count == -1) {
            return 0;
        } else {
            return $count;
        }
    }

    private function growStates()
    {
        if ($this->nextState + 2 > count($this->states)) {
            $this->states = Util::growIntArray($this->states, $this->nextState + 2);
        }
    }

    private function growTransitions()
    {
        if ($this->nextTransition + 3 > count($this->transitions)) {
            $this->transitions = Util::growIntArray($this->transitions, $this->nextTransition + 3);
        }
    }

    /** Initialize the provided Transition to iterate through all transitions
     *  leaving the specified state.  You must call {@link #getNextTransition} to
     *  get each transition.  Returns the number of transitions
     *  leaving this state. */
    public function initTransition(int $state, Transition $t)
    {
//    assert state < nextState/2: "state=" + state + " nextState=" + nextState;
        if (!($state < $this->nextState / 2)) {
            throw new \AssertionError("state=" . $state . " nextState=" . $this->nextState);
        }
        $t->source = $state;
        $t->transitionUpto = $this->states[2 * $state];
        return $this->getNumTransitions($state);
    }

    /** Iterate to the next transition after the provided one */
    public function getNextTransition(Transition $t)
    {
        // Make sure there is still a transition left:
//    assert (t.transitionUpto+3 - states[2*t.source]) <= 3*states[2*t.source+1];
        if (!(($t->transitionUpto + 3 - $this->states[2 * $t->source]) <= 3 * $this->states[2 * $t->source + 1])) {
            throw new \AssertionError('NOT: ((t.transitionUpto+3 - states[2*t.source]) <= 3*states[2*t.source+1])');
        }

        // Make sure transitions are in fact sorted:
//    assert transitionSorted(t);
        if (!($this->transitionSorted($t))) {
            throw new \AssertionError('NOT: (transitionSorted(t))');
        }

        $t->dest = $this->transitions[$t->transitionUpto++];
        $t->min = $this->transitions[$t->transitionUpto++];
        $t->max = $this->transitions[$t->transitionUpto++];
    }

    private function transitionSorted(Transition $t): bool
    {

        $upto = $t->transitionUpto;
        if ($upto == $this->states[2 * $t->source]) {
            // Transition isn't initialzed yet (this is the first transition); don't check:
            return true;
        }

        $nextDest = $this->transitions[$upto];
        $nextMin = $this->transitions[$upto + 1];
        $nextMax = $this->transitions[$upto + 2];
        if ($nextMin > $t->min) {
            return true;
        } else {
            if ($nextMin < $t->min) {
                return false;
            }
        }

        // Min is equal, now test max:
        if ($nextMax > $t->max) {
            return true;
        } else {
            if ($nextMax < $t->max) {
                return false;
            }
        }

        // Max is also equal, now test dest:
        if ($nextDest > $t->dest) {
            return true;
        } else {
            if ($nextDest < $t->dest) {
                return false;
            }
        }

        // We should never see fully equal transitions here:
        return false;
    }

    /** Fill the provided {@link Transition} with the index'th
     *  transition leaving the specified state. */
    public function getTransition(int $state, int $index, Transition $t)
    {
        $i = $this->states[2 * $state] + 3 * $index;
        $t->source = $state;
        $t->dest = $this->transitions[$i++];
        $t->min = $this->transitions[$i++];
        $t->max = $this->transitions[$i++];
    }

    static function appendCharString(int $c, &$b)
    {
        if ($c >= 0x21 && $c <= 0x7e && chr($c) != '\\' && chr($c) != '"') {
            $b .= \IntlChar::chr($c);
        } else {
            $b .= "\\\\U";
            $s = dechex($c);
            if ($c < 0x10) $b .= "0000000" . $s;
            elseif ($c < 0x100) $b .= "000000" . $s;
            elseif ($c < 0x1000) $b .= "00000" . $s;
            elseif ($c < 0x10000) $b .= "0000" . $s;
            elseif ($c < 0x100000) $b .= "000" . $s;
            elseif ($c < 0x1000000) $b .= "00" . $s;
            elseif ($c < 0x10000000) $b .= "0" . $s;
            else $b .= $s;
        }
    }

  /*
  public void writeDot(String fileName) {
    if (fileName.indexOf('/') == -1) {
      fileName = "/l/la/lucene/core/" + fileName + ".dot";
    }
    try {
      PrintWriter pw = new PrintWriter(fileName);
      pw.println(toDot());
      pw.close();
    } catch (IOException ioe) {
      throw new RuntimeException(ioe);
    }
  }
  */

    /** Returns the dot (graphviz) representation of this automaton.
     *  This is extremely useful for visualizing the automaton. */
    public function toDot(): string
    {
        // TODO: breadth first search so we can get layered output...

        $b = '';
        $b .= "digraph Automaton {\n";
        $b .= "  rankdir = LR\n";
        $b .= "  node [width=0.2, height=0.2, fontsize=8]\n";
        $numStates = $this->getNumStates();
        if ($numStates > 0) {
            $b .= "  initial [shape=plaintext,label=\"\"]\n";
            $b .= "  initial -> 0\n";
        }

        $t = new Transition();

        for ($state = 0; $state < $numStates; $state++) {
            $b .= "  ";
            $b .= $state;
            if ($this->isAccept($state)) {
                $b .= " [shape=doublecircle,label=\"" . $state . "\"]\n";
            } else {
                $b .= " [shape=circle,label=\"" . $state . "\"]\n";
            }
            $numTransitions = $this->initTransition($state, $t);
            //System.out.println("toDot: state " . $state . " has " + numTransitions + " transitions;$t->nextTrans=" +$t->transitionUpto);
            for ($i = 0; $i < $numTransitions; $i++) {
                $this->getNextTransition(t);
                //System.out.println(" $t->nextTrans=" +$t->transitionUpto + " t=" + t);
//        assert $t->max >=$t->min;
                if (!($t->max >= $t->min)) {
                    throw new \AssertionError('NOT: ($t->max >=$t->min)');
                }

                $b .= "  ";
                $b .= $state;
                $b .= " -> ";
                $b .= $t->dest;
                $b .= " [label=\"";
                $this->appendCharString($t->min, $b);
                if ($t->max != $t->min) {
                    $b .= '-';
                    $this->appendCharString($t->max, $b);
                }
                $b .= "\"]\n";
                //System.out.println("  t=" + t);
            }
        }
        $b .= '}';
        return $b;
    }

  /**
   * Returns sorted array of all interval start points.
   */
    public function getStartPoints(): array
    {
        $pointset = [];
        $pointset[] = \IntlChar::CODEPOINT_MIN;
        //System.out.println("getStartPoints");
        for ($s = 0; $s < $this->nextState; $s += 2) {
            $trans = $this->states[$s];
            $limit = $trans + 3 * $this->states[$s + 1];
            error_log("  state=" . ($s/2) . " trans=" . $trans . " limit=" . $limit);
            while ($trans < $limit) {
                $min = $this->transitions[$trans + 1];
                $max = $this->transitions[$trans + 2];
                error_log("    min=" . $min);
                error_log("    max=" . $max);
                $pointset[] = $min;
                if ($max < \IntlChar::CODEPOINT_MAX) {
                    $pointset[] = $max + 1;
                }
                $trans += 3;
            }
        }
//        $points = []; //new int[$pointset.size()];
        $points = array_fill(0, count($pointset), 0);
        $n = 0;
        foreach ($pointset as $m) {
            $points[$n++] = $m;
        }
        sort($points);
        return $points;
    }

    /**
     * Performs lookup in transitions, assuming determinism.
     *
     * @param int $state starting state
     * @param int $label codepoint to look up
     *
     * @return int destination state, -1 if no matching outgoing transition
     */
    public function step(int $state, int $label): int
    {
//        assert state >= 0;
        if (!($state >= 0)) {
            throw new \AssertionError('NOT: (state >= 0)');
        }
//        assert label >= 0;
        if (!($label >= 0)) {
            throw new \AssertionError('NOT: (state >= 0)');
        }
        $trans = $this->states[2 * $state];
        $limit = $trans + 3 * $this->states[2 * $state + 1];
        // TODO: we could do bin search; transitions are sorted
        while ($trans < $limit) {
            $dest = $this->transitions[$trans];
            $min = $this->transitions[$trans + 1];
            $max = $this->transitions[$trans + 2];
            if ($min <= $label && $label <= $max) {
                return $dest;
            }
            $trans += 3;
        }

        return -1;
    }
}


/** Records new states and transitions and then {@link
 *  #finish} creates the {@link Automaton}.  Use this
 *  when you cannot create the Automaton directly because
 *  it's too restrictive to have to add all transitions
 *  leaving each state at once. */
class Builder
{
    public $nextState = 0;
    /** @var BitSet */
    public $isAccept;
    public $transitions = [];
    public $nextTransition = 0;

    /** Sorts transitions first then min label ascending, then
     *  max label ascending, then dest ascending
     * @var InPlaceMergeSorter
     */
    protected $sorter;

    /**
     * Constructor which creates a builder with enough space for the given
     * number of states and transitions.
     *
     * @param numStates
     *           Number of states.
     * @param numTransitions
     *           Number of transitions.
     */
    public function __construct(int $numStates = 16, int $numTransitions = 16)
    {
        $this->isAccept = new BitSet($numStates);
        $this->transitions = []; //new int[numTransitions * 4];

        $this->sorter = new class($this) extends InPlaceMergeSorter
        {
            protected $parent;

            public function __construct($parent)
            {
                parent::__construct();
                $this->parent = $parent;
            }

            private function swapOne(int $i, int $j)
            {
                $x = $this->parent->transitions[$i];
                $this->parent->transitions[$i] = $this->parent->transitions[$j];
                $this->parent->transitions[$j] = $x;
            }

            protected function swap(int $i, int $j)
            {
                $iStart = 4 * $i;
                $jStart = 4 * $j;
                $this->swapOne($iStart, $jStart);
                $this->swapOne($iStart + 1, $jStart + 1);
                $this->swapOne($iStart + 2, $jStart + 2);
                $this->swapOne($iStart + 3, $jStart + 3);
            }

            protected function compare(int $i, int $j): int
            {
                $iStart = 4 * $i;
                $jStart = 4 * $j;

                // First src:
                $iSrc = $this->parent->transitions[$iStart];
                $jSrc = $this->parent->transitions[$jStart];
                if ($iSrc < $jSrc) {
                    return -1;
                } else {
                    if ($iSrc > $jSrc) {
                        return 1;
                    }
                }

                // Then min:
                $iMin = $this->parent->transitions[$iStart + 2];
                $jMin = $this->parent->transitions[$jStart + 2];
                if ($iMin < $jMin) {
                    return -1;
                } else {
                    if ($iMin > $jMin) {
                        return 1;
                    }
                }

                // Then max:
                $iMax = $this->parent->transitions[$iStart + 3];
                $jMax = $this->parent->transitions[$jStart + 3];
                if ($iMax < $jMax) {
                    return -1;
                } else {
                    if ($iMax > $jMax) {
                        return 1;
                    }
                }

                // First dest:
                $iDest = $this->parent->transitions[$iStart + 1];
                $jDest = $this->parent->transitions[$jStart + 1];
                if ($iDest < $jDest) {
                    return -1;
                } else {
                    if ($iDest > $jDest) {
                        return 1;
                    }
                }

                return 0;
            }
        };
    }

    /** Add a new transition with the specified source, dest, min, max. */
    public function addTransition(int $source, int $dest, int $min, int $max = null)
    {
        if ($max === null) {
            $max = $min;
        }
        if (count($this->transitions) < $this->nextTransition + 4) {
            $this->transitions = Util::growIntArray($this->transitions, $this->nextTransition + 4);
        }
        $this->transitions[$this->nextTransition++] = $source;
        $this->transitions[$this->nextTransition++] = $dest;
        $this->transitions[$this->nextTransition++] = $min;
        $this->transitions[$this->nextTransition++] = $max;
    }

    /** Add a [virtual] epsilon transition between source and dest.
     *  Dest state must already have all transitions added because this
     *  method simply copies those same transitions over to source. */
    public function addEpsilon(int $source, int $dest)
    {
        for ($upto = 0; $upto < $this->nextTransition; $upto += 4) {
            if ($this->transitions[$upto] == $dest) {
                $this->addTransition($source, $this->transitions[$upto + 1], $this->transitions[$upto + 2], $this->transitions[$upto + 3]);
            }
        }
        if ($this->isAccept($dest)) {
            $this->setAccept($source, true);
        }
    }

    /** Compiles all added states and transitions into a new {@code Automaton}
     *  and returns it. */
    public function finish(): Automaton
    {
        // Create automaton with the correct size.
        $numStates = $this->nextState;
        $numTransitions = $this->nextTransition / 4;
        $a = new Automaton($numStates, $numTransitions);

        // Create all states.
        for ($state = 0; $state < $numStates; $state++) {
            $a->createState();
            $a->setAccept($state, $this->isAccept($state));
        }

        // Create all transitions
        $this->sorter->sort(0, $numTransitions);
        for ($upto = 0; $upto < $this->nextTransition; $upto += 4) {
            $a->addTransition($this->transitions[$upto],
                $this->transitions[$upto + 1],
                $this->transitions[$upto + 2],
                $this->transitions[$upto + 3]);
        }

        $a->finishState();

        return $a;
    }

    /** Create a new state. */
    public function createState()
    {
        return $this->nextState++;
    }

    /** Set or clear this state as an accept state. */
    public function setAccept(int $state, bool $accept)
    {
        if ($state >= $this->getNumStates()) {
            throw new IllegalArgumentException("state=" . $state . " is out of bounds (numStates=" . $this->getNumStates() . ")");
        }

        $this->isAccept->set($state, $accept);
    }

    /** Returns true if this state is an accept state. */
    public function isAccept(int $state): bool
    {
        return $this->isAccept->get($state);
    }

    /** How many states this automaton has. */
    public function getNumStates(): int
    {
        return $this->nextState;
    }

    /** Copies over all states/transitions from $other-> */
    public function copy(Automaton $other)
    {
        $offset = $this->getNumStates();
        $otherNumStates = $other->getNumStates();

        // Copy all states
        $this->copyStates($other);

        // Copy all transitions
        $t = new Transition();
        for ($s = 0; $s < $otherNumStates; $s++) {
            $count = $other->initTransition($s, $t);
            for ($i = 0; $i < $count; $i++) {
                $other->getNextTransition($t);
                $this->addTransition($offset + $s, $offset + $t->dest, $t->min, $t->max);
            }
        }
    }

    /** Copies over all states from $other-> */
    public function copyStates(Automaton $other)
    {
        $otherNumStates = $other->getNumStates();
        for ($s = 0; $s < $otherNumStates; $s++) {
            $newState = $this->createState();
            $this->setAccept($newState, $other->isAccept($s));
        }
    }
}

//  public long ramBytesUsed() {
//    // TODO: BitSet RAM usage (isAccept.size()/8) isn't fully accurate...
//    return RamUsageEstimator.NUM_BYTES_OBJECT_HEADER + RamUsageEstimator.sizeOf(states) + RamUsageEstimator.sizeOf(transitions) +
//        RamUsageEstimator.NUM_BYTES_OBJECT_HEADER + (isAccept.size() / 8) + RamUsageEstimator.NUM_BYTES_OBJECT_REF +
//        2 * RamUsageEstimator.NUM_BYTES_OBJECT_REF +
//        3 * Integer.BYTES +
//        1;
//  }
