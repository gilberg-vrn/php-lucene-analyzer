<?php

namespace ftIndex\util\automaton;

/**
 * Class MinimizationOperations
 *
 * @package ftIndex\util\automaton
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/20/19 9:12 PM
 */
final class MinimizationOperations
{
    private function __contruct()
    {
    }

    public static function minimize(Automaton $a, int $maxDeterminizedStates): Automaton
    {
        if ($a->getNumStates() == 0 || ($a->isAccept(0) == false && $a->getNumTransitions(0) == 0)) {
            // Fastmatch for common case
            return new Automaton();
        }
        $a = Operations::determinize($a, $maxDeterminizedStates);
        //a.writeDot("adet");
        if ($a->getNumTransitions(0) == 1) {
            $t = new Transition();
            $a->getTransition(0, 0, $t);
            if ($t->dest == 0 && $t->min == \IntlChar::CODEPOINT_MIN
                && $t->max == \IntlChar::CODEPOINT_MAX) {
                // Accepts all strings
                return $a;
            }
        }
        $a = Operations::totalize($a);
        //a.writeDot("atot");

        // initialize data structures
        $sigma = $a->getStartPoints();
        $sigmaLen = count($sigma);
        $statesLen = $a->getNumStates();

        $reverse = []; //(ArrayList<Integer>[][]) new ArrayList[statesLen][sigmaLen];
        $partition = []; //(HashSet<Integer>[]) new HashSet[statesLen];
        $splitblock = []; //(ArrayList<Integer>[]) new ArrayList[statesLen];
        $block = []; //new int[statesLen];
        /** @var StateList[][] $active */
        $active = []; //new StateList[statesLen][sigmaLen];
        /** @var StateListNode[][] $active2 */
        $active2 = []; //new StateListNode[statesLen][sigmaLen];
        /** @var IntPair[] $pending */
        $pending = []; //new LinkedList<>();
        $pending2 = new BitSet($sigmaLen * $statesLen);
        $split = new BitSet($statesLen);
        $refine = new BitSet($statesLen);
        $refine2 = new BitSet($statesLen);
        for ($q = 0; $q < $statesLen; $q++) {
            $splitblock[$q] = []; //new ArrayList<>();
            $partition[$q] = []; //new HashSet<>();
            for ($x = 0; $x < $sigmaLen; $x++) {
                $active[$q][$x] = new StateList();
            }
        }
        // find initial partition and reverse edges
        for ($q = 0; $q < $statesLen; $q++) {
            $j = $a->isAccept($q) ? 0 : 1;
            $partition[$j][] = $q;
            $block[$q] = $j;
            for ($x = 0; $x < $sigmaLen; $x++) {
                $step = $a->step($q, $sigma[$x]);
                if (!isset($reverse[$step][$x])) {
                    $reverse[$step][$x] = []; //new ArrayList<>();
                }
                $reverse[$step][$x][] = $q;
            }
        }
        // initialize active sets
        for ($j = 0; $j <= 1; $j++) {
            for ($x = 0; $x < $sigmaLen; $x++) {
                foreach ($partition[$j] as $q) {
                    if (isset($reverse[$q][$x])) {
                        $active2[$q][$x] = $active[$j][$x]->add($q);
                    }
                }
            }
        }

        // initialize pending
        for ($x = 0; $x < $sigmaLen; $x++) {
            $j = ($active[0][$x]->size <= $active[1][$x]->size) ? 0 : 1;
            $pending[] = new IntPair($j, $x);
            $pending2->set($x * $statesLen + $j);
        }

        // process pending until fixed point
        $k = 2;
        //System.out->println("start min");
        while (!empty($pending)) {
            //System.out->println("  cycle pending");
            $ip = array_shift($pending);
            $p = $ip->n1;
            $x = $ip->n2;
            //System.out->println("    pop n1=" + ip.n1 + " n2=" + ip.n2);
            $pending2->clear($x * $statesLen + $p);
            // find states that need to be split off their blocks
            /** @var StateListNode $m */
            for ($m = $active[$p][$x]->first; $m != null; $m = $m->next) {
                $r = $reverse[$m->q][$x] ?? null;
                if ($r != null) {
                    foreach ($r as $i) {
                        if (!$split->get($i)) {
                            $split->set($i);
                            $j = $block[$i];
                            $splitblock[$j][] = $i;
                            if (!$refine2->get($j)) {
                                $refine2->set($j);
                                $refine->set($j);
                            }
                        }
                    }
                }
            }

            // refine blocks
            for ($j = $refine->nextSetBit(0); $j >= 0; $j = $refine->nextSetBit($j + 1)) {
                if (count($splitblock[$j]) < count($partition[$j])) {
                    foreach ($splitblock[$j] as $s) {
//                        $partition[$j]->remove($s); -->
                        $ri = array_search($s, $partition[$j]);
                        unset($partition[$j][$ri]);
                        $partition[$j] = array_values($partition[$j]);
//                     <--remove
                        $partition[$k][] = $s;
                        $block[$s] = $k;
                        for ($c = 0; $c < $sigmaLen; $c++) {
                            if (isset($active2[$s][$c]) && $active2[$s][$c]->sl == $active[$j][$c]) {
                                $active2[$s][$c]->remove();
                                $active2[$s][$c] = $active[$k][$c]->add($s);
                            }
                        }
                    }
                    // update pending
                    for ($c = 0; $c < $sigmaLen; $c++) {
                        $aj = $active[$j][$c]->size;
                        $ak = $active[$k][$c]->size;
                        $ofs = $c * $statesLen;
                        if (!$pending2->get($ofs + $j) && 0 < $aj && $aj <= $ak) {
                            $pending2->set($ofs + $j);
                            $pending[] = (new IntPair($j, $c));
                        } else {
                            $pending2->set($ofs + $k);
                            $pending[] = (new IntPair($k, $c));
                        }
                    }
                    $k++;
                }
                $refine2->clear($j);
                foreach ($splitblock[$j] as $s) {
                    $split->clear($s);
                }
                $splitblock[$j] = [];
            }
            $refine->clear();
        }

        $result = new Automaton();

        $t = new Transition();

        //System.out->println("  k=" + k);

        // make a new state for each equivalence class, set initial state
        $stateMap = []; //new int[statesLen];
        $stateRep = array_fill(0, $k, 0); //new int[k];

        $result->createState();

        //System.out->println("min: k=" + k);
        for ($n = 0; $n < $k; $n++) {
            //System.out->println("    n=" + n);

            $isInitial = false;
            foreach ($partition[$n] as $q) {
                if ($q == 0) {
                    $isInitial = true;
                    //System.out->println("    isInitial!");
                    break;
                }
            }

            if ($isInitial) {
                $newState = 0;
            } else {
                $newState = $result->createState();
            }

            //System.out->println("  newState=" + newState);

            foreach ($partition[$n] as $q) {
                $stateMap[$q] = $newState;
                //System.out->println("      q=" + q + " isAccept?=" + a.isAccept(q));
                $result->setAccept($newState, $a->isAccept($q));
                $stateRep[$newState] = $q;   // select representative
            }
        }

        // build transitions and set acceptance
        for ($n = 0; $n < $k; $n++) {
            $numTransitions = $a->initTransition($stateRep[$n], $t);
            for ($i = 0; $i < $numTransitions; $i++) {
                $a->getNextTransition($t);
                //System.out->println("  add trans");
                $result->addTransition($n, $stateMap[$t->dest], $t->min, $t->max);
            }
        }
        $result->finishState();
        //System.out->println(result->getNumStates() + " states");

        return Operations::removeDeadStates($result);
    }
}


final class StateListNode
{
    public $q;
    /** @var StateListNode */
    public $next;
    /** @var StateListNode */
    public $prev;
    /** @var StateList */
    public $sl;

    public function __construct(int $q, StateList $sl)
    {
        $this->q = $q;
        $this->sl = $sl;
        if ($sl->size++ == 0) {
            $sl->first = $sl->last = $this;
        } else {
            $sl->last->next = $this;
            $this->prev = $sl->last;
            $sl->last = $this;
        }

    }

    public function remove()
    {
        --$this->sl->size;
        if ($this->sl->first == $this) {
            $this->sl->first = $this->next;
        } else {
            $this->prev->next = $this->next;
        }

        if ($this->sl->last == $this) {
            $this->sl->last = $this->prev;
        } else {
            $this->next->prev = $this->prev;
        }
    }
}

final class StateList
{
    public $size;
    /** @var StateListNode */
    public $first;
    /** @var StateListNode */
    public $last;

    public function __construct()
    {
    }

    public function add(int $q): StateListNode
    {
        return new StateListNode($q, $this);
    }
}

final class IntPair
{
    public $n1;
    public $n2;

    public function __construct(int $n1, int $n2)
    {
        $this->n1 = $n1;
        $this->n2 = $n2;
    }
}