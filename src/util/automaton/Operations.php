<?php

namespace ftIndex\util\automaton;

use ftIndex\analyses\hunspell\IllegalArgumentException;
use ftIndex\fst\Util;

/**
 * Class Operations
 *
 * @package ftIndex\util\automaton
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/24/19 2:14 PM
 */
class Operations
{
    /**
     * Default maximum number of states that {@link Operations#determinize} should create.
     */
    const DEFAULT_MAX_DETERMINIZED_STATES = 10000;

    private function __construct()
    {
    }

    /**
     * Returns an automaton that accepts the concatenation of the languages of the
     * given Automata::
     * <p>
     * Complexity: linear in total number of states.
     */
    static public function concatenate(Automaton $a1, Automaton $a2): Automaton
    {
        return self::concatenateAsArray([$a1, $a2]);
    }

    /**
     * Returns an automaton that accepts the concatenation of the languages of the
     * given Automata::
     * <p>
     * Complexity: linear in total number of states.
     *
     * @param Automaton[] $l
     *
     * @return Automaton
     */
    static public function concatenateAsArray(array $l): Automaton
    {
        $result = new Automaton();

        // First pass: create all states
        /** @var Automaton $a */
        foreach ($l as $a) {
            if ($a->getNumStates() == 0) {
                $result->finishState();
                return $result;
            }
            $numStates = $a->getNumStates();
            for ($s = 0; $s < $numStates; $s++) {
                $result->createState();
            }
        }

        // Second pass: add transitions, carefully linking accept
        // states of A to init state of next A:
        $stateOffset = 0;
        $t = new Transition();
        for ($i = 0; $i < count($l); $i++) {
            $a = $l[$i];
            $numStates = $a->getNumStates();

            $nextA = ($i == count($l) - 1) ? null : $l[$i + 1];

            for ($s = 0; $s < $numStates; $s++) {
                $numTransitions = $a->initTransition($s, $t);
                for ($j = 0; $j < $numTransitions; $j++) {
                    $a->getNextTransition($t);
                    $result->addTransition($stateOffset + $s, $stateOffset + $t->dest, $t->min, $t->max);
                }

                if ($a->isAccept($s)) {
                    $followA = $nextA;
                    $followOffset = $stateOffset;
                    $upto = $i + 1;
                    while (true) {
                        if ($followA != null) {
                            // Adds a "virtual" epsilon transition:
                            $numTransitions = $followA->initTransition(0, $t);
                            for ($j = 0; $j < $numTransitions; $j++) {
                                $followA->getNextTransition($t);
                                $result->addTransition($stateOffset + $s, $followOffset + $numStates + $t->dest, $t->min, $t->max);
                            }
                            if ($followA->isAccept(0)) {
                                // Keep chaining if followA accepts empty string
                                $followOffset += $followA->getNumStates();
                                $followA = ($upto == count($l) - 1) ? null : $l[$upto + 1];
                                $upto++;
                            } else {
                                break;
                            }
                        } else {
                            $result->setAccept($stateOffset + $s, true);
                            break;
                        }
                    }
                }
            }

            $stateOffset += $numStates;
        }

        if ($result->getNumStates() == 0) {
            $result->createState();
        }

        $result->finishState();

        return $result;
    }

    /**
     * Returns an automaton that accepts the union of the empty string and the
     * language of the given automaton.  This may create a dead state.
     * <p>
     * Complexity: linear in number of states.
     */
    static public function optional(Automaton $a): Automaton
    {
        $result = new Automaton();
        $result->createState();
        $result->setAccept(0, true);
        if ($a->getNumStates() > 0) {
            $result->copy($a);
            $result->addEpsilon(0, 1);
        }
        $result->finishState();
        return $result;
    }

    /**
     * Returns an automaton that accepts the Kleene star (zero or more
     * concatenated repetitions) of the language of the given automaton. Never
     * modifies the input automaton language.
     * <p>
     * Complexity: linear in number of states.
     */
    static public function repeat(Automaton $a): Automaton
    {
        if ($a->getNumStates() == 0) {
            // Repeating the empty automata will still only accept the empty Automata::
            return $a;
        }
        $builder = new Builder();
        $builder->createState();
        $builder->setAccept(0, true);
        $builder->copy($a);

        $t = new Transition();
        $count = $a->initTransition(0, $t);
        for ($i = 0; $i < $count; $i++) {
            $a->getNextTransition($t);
            $builder->addTransition(0, $t->dest + 1, $t->min, $t->max);
        }

        $numStates = $a->getNumStates();
        for ($s = 0; $s < $numStates; $s++) {
            if ($a->isAccept($s)) {
                $count = $a->initTransition(0, $t);
                for ($i = 0; $i < $count; $i++) {
                    $a->getNextTransition($t);
                    $builder->addTransition($s + 1, $t->dest + 1, $t->min, $t->max);
                }
            }
        }

        return $builder->finish();
    }

    /**
     * Returns an automaton that accepts <code>min</code> or more concatenated
     * repetitions of the language of the given automaton.
     * <p>
     * Complexity: linear in number of states and in <code>min</code>.
     */
    static public function repeatCount(Automaton $a, int $count): Automaton
    {
        if ($count == 0) {
            return self::repeat($a);
        }
        $as = []; //new ArrayList<>();
        while ($count-- > 0) {
            $as[] = $a;
        }
        $as[] = self::repeat($a);
        return self::concatenateAsArray($as);
    }

    /**
     * Returns an automaton that accepts between <code>min</code> and
     * <code>max</code> (including both) concatenated repetitions of the language
     * of the given automaton.
     * <p>
     * Complexity: linear in number of states and in <code>min</code> and
     * <code>max</code>.
     */
    static public function repeatMinMax(Automaton $a, int $min, int $max): Automaton
    {
        if ($min > $max) {
            return Automata::makeEmpty();
        }

        if ($min == 0) {
            $b = Automata::makeEmptyString();
        } else {
            if ($min == 1) {
                $b = new Automaton();
                $b->copy($a);
            } else {
                $as = []; //new ArrayList<>();
                for ($i = 0; $i < $min; $i++) {
                    $as[] = $a;
                }
                $b = self::concatenateAsArray($as);
            }
        }

        $prevAcceptStates = self::toSet($b, 0);
        $builder = new Builder();
        $builder->copy($b);
        for ($i = $min; $i < $max; $i++) {
            $numStates = $builder->getNumStates();
            $builder->copy($a);
            foreach ($prevAcceptStates as $s) {
                $builder->addEpsilon($s, $numStates);
            }
            $prevAcceptStates = self::toSet($a, $numStates);
        }

        return $builder->finish();
    }

    private static function toSet(Automaton $a, int $offset): array
    {
        $numStates = $a->getNumStates();
        $isAccept = $a->getAcceptStates();
        $result = []; //new HashSet<Integer>();
        $upto = 0;
        while ($upto < $numStates && ($upto = $isAccept->nextSetBit($upto)) != -1) {
            $result[] = $offset + $upto;
            $upto++;
        }

        return $result;
    }

    /**
     * Returns a (deterministic) automaton that accepts the complement of the
     * language of the given automaton.
     * <p>
     * Complexity: linear in number of states if already deterministic and
     *  exponential otherwise.
     *
     * @param maxDeterminizedStates maximum number of states determinizing the
     *  automaton can result in.  Set higher to allow more complex queries and
     *  lower to prevent memory exhaustion.
     */
    static public function complement(Automaton $a, int $maxDeterminizedStates): Automaton
    {
        $a = self::totalize(self::determinize($a, $maxDeterminizedStates));
        $numStates = $a->getNumStates();
        for ($p = 0; $p < $numStates; $p++) {
            $a->setAccept($p, !$a->isAccept($p));
        }
        return self::removeDeadStates($a);
    }

    /**
     * Returns a (deterministic) automaton that accepts the intersection of the
     * language of <code>a1</code> and the complement of the language of
     * <code>a2</code>. As a side-effect, the automata may be determinized, if not
     * already deterministic.
     * <p>
     * Complexity: quadratic in number of states if a2 already deterministic and
     *  exponential in number of a2's states otherwise.
     */
    static public function minus(Automaton $a1, Automaton $a2, int $maxDeterminizedStates): Automaton
    {
        if (Operations::isEmpty($a1) || $a1 == $a2) {
            return Automata::makeEmpty();
        }
        if (Operations::isEmpty($a2)) {
            return $a1;
        }
        return self::intersection($a1, self::complement($a2, $maxDeterminizedStates));
    }

    /**
     * Returns an automaton that accepts the intersection of the languages of the
     * given Automata:: Never modifies the input automata languages.
     * <p>
     * Complexity: quadratic in number of states.
     */
    static public function intersection(Automaton $a1, Automaton $a2): Automaton
    {
        if ($a1 == $a2) {
            return $a1;
        }
        if ($a1->getNumStates() == 0) {
            return $a1;
        }
        if ($a2->getNumStates() == 0) {
            return $a2;
        }
        $transitions1 = $a1->getSortedTransitions();
        $transitions2 = $a2->getSortedTransitions();
        $c = new Automaton();
        $c->createState();
        $worklist = []; //new ArrayDeque<>();
        $newstates = []; //new HashMap<>();
        $p = new StatePair(0, 0, 0);
        $worklist[] = $p;
        $newstates[(string)$p] = $p;
        while (count($worklist) > 0) {
            $p = array_shift($worklist);
            $c->setAccept($p->s, $a1->isAccept($p->s1) && $a2->isAccept($p->s2));
            $t1 = $transitions1[$p->s1];
            $t2 = $transitions2[$p->s2];
            for ($n1 = 0, $b2 = 0; $n1 < count($t1); $n1++) {
                while ($b2 < count($t2) && $t2[$b2]->max < $t1[$n1]->min) {
                    $b2++;
                }
                for ($n2 = $b2; $n2 < count($t2) && $t1[$n1]->max >= $t2[$n2]->min; $n2++) {
                    if ($t2[$n2]->max >= $t1[$n1]->min) {
                        $q = new StatePair(-1, $t1[$n1]->dest, $t2[$n2]->dest);
                        $r = $newstates[(string)$q] ?? null;
                        if ($r == null) {
                            $q->s = $c->createState();
                            $worklist[] = $q;
                            $newstates[(string)$q] = $q;
                            $r = $q;
                        }
                        $min = $t1[$n1]->min > $t2[$n2]->min ? $t1[$n1]->min : $t2[$n2]->min;
                        $max = $t1[$n1]->max < $t2[$n2]->max ? $t1[$n1]->max : $t2[$n2]->max;
                        $c->addTransition($p->s, $r->s, $min, $max);
                    }
                }
            }
        }
        $c->finishState();

        return self::removeDeadStates($c);
    }

    /** Returns true if these two automata accept exactly the
     *  same language.  This is a costly computation!  Both automata
     *  must be determinized and have no dead states! */
    public static function sameLanguage(Automaton $a1, Automaton $a2): bool
    {
        if ($a1 == $a2) {
            return true;
        }
        return self::subsetOf($a2, $a1) && self::subsetOf($a1, $a2);
    }

    // TODO: move to test-framework?

    /** Returns true if this automaton has any states that cannot
     *  be reached from the initial state or cannot reach an accept state.
     *  Cost is O(numTransitions+numStates). */
    public static function hasDeadStates(Automaton $a): bool
    {
        $liveStates = self::getLiveStates($a);
        $numLive = $liveStates->cardinality();
        $numStates = $a->getNumStates();
//    assert numLive <= numStates: "numLive=" + numLive + " numStates=" + numStates + " " + liveStates;
        if (!($numLive <= $numStates)) {
            throw new \AssertionError("numLive=" . $numLive . " numStates=" . $numStates . " " . $liveStates);
        }
        return $numLive < $numStates;
    }

    // TODO: move to test-framework?

    /** Returns true if there are dead states reachable from an initial state. */
    public static function hasDeadStatesFromInitial(Automaton $a)
    {
        $reachableFromInitial = self::getLiveStatesFromInitial($a);
        $reachableFromAccept = self::getLiveStatesToAccept($a);
        $reachableFromInitial->andNot($reachableFromAccept);
        return $reachableFromInitial->isEmpty() == false;
    }

    // TODO: move to test-framework?

    /** Returns true if there are dead states that reach an accept state. */
    public static function hasDeadStatesToAccept(Automaton $a)
    {
        $reachableFromInitial = self::getLiveStatesFromInitial($a);
        $reachableFromAccept = self::getLiveStatesToAccept($a);
        $reachableFromAccept->andNot($reachableFromInitial);
        return $reachableFromAccept->isEmpty() == false;
    }

    /**
     * Returns true if the language of <code>a1</code> is a subset of the language
     * of <code>a2</code>. Both automata must be determinized and must have no dead
     * states.
     * <p>
     * Complexity: quadratic in number of states.
     */
    public static function subsetOf(Automaton $a1, Automaton $a2): bool
    {
        if ($a1->isDeterministic() == false) {
            throw new IllegalArgumentException("a1 must be deterministic");
        }
        if ($a2->isDeterministic() == false) {
            throw new IllegalArgumentException("a2 must be deterministic");
        }
//    assert hasDeadStatesFromInitial(a1) == false;
        if (!(self::hasDeadStatesFromInitial($a1) == false)) {
            throw new \AssertionError('NOT: (hasDeadStatesFromInitial(a1) == false');
        }
//    assert hasDeadStatesFromInitial(a2) == false;
        if (!(self::hasDeadStatesFromInitial($a2) == false)) {
            throw new \AssertionError('NOT: (hasDeadStatesFromInitial(a2) == false');
        }
        if ($a1->getNumStates() == 0) {
            // Empty language is alwyas a subset of any other language
            return true;
        } else {
            if ($a2->getNumStates() == 0) {
                return self::isEmpty($a1);
            }
        }

        // TODO: cutover to iterators instead
        $transitions1 = $a1->getSortedTransitions();
        $transitions2 = $a2->getSortedTransitions();
        $worklist = []; //new ArrayDeque<>();
        $visited = []; //new HashSet<>();
        $p = new StatePair(-1, 0, 0);
        $worklist[] = $p;
        $visited[] = $p;
        while (count($worklist) > 0) {
            $p = array_shift($worklist);
            if ($a1->isAccept($p->s1) && $a2->isAccept($p->s2) == false) {
                return false;
            }
            $t1 = $transitions1[$p->s1];
            $t2 = $transitions2[$p->s2];
            for ($n1 = 0, $b2 = 0; $n1 < count($t1); $n1++) {
                while ($b2 < count($t2) && $t2[$b2]->max < $t1[$n1]->min) {
                    $b2++;
                }
                $min1 = $t1[$n1]->min;
                $max1 = $t1[$n1]->max;

                for ($n2 = $b2; $n2 < count($t2) && $t1[$n1]->max >= $t2[$n2]->min; $n2++) {
                    if ($t2[$n2]->min > $min1) {
                        return false;
                    }
                    if ($t2[$n2]->max < \IntlChar::CODEPOINT_MAX) {
                        $min1 = $t2[$n2]->max + 1;
                    } else {
                        $min1 = \IntlChar::CODEPOINT_MAX;
                        $max1 = \IntlChar::CODEPOINT_MIN;
                    }
                    $q = new StatePair(-1, $t1[$n1]->dest, $t2[$n2]->dest);
                    if (!in_array($q, $visited)) {
                        $worklist[] = $q;
                        $visited[] = $q;
                    }
                }
                if ($min1 <= $max1) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Returns an automaton that accepts the union of the languages of the given
     * Automata::
     * <p>
     * Complexity: linear in number of states.
     */
    public static function union(Automaton $a1, Automaton $a2): Automaton
    {
        return self::unionAsArray([$a1, $a2]);
    }

    /**
     * Returns an automaton that accepts the union of the languages of the given
     * Automata::
     * <p>
     * Complexity: linear in number of states.
     *
     * @param Automaton[] $l
     *
     * @return Automaton
     */
    public static function unionAsArray(array $l): Automaton
    {
        $result = new Automaton();

        // Create initial state:
        $result->createState();

        // Copy over all automata
        foreach ($l as $a) {
            $result->copy($a);
        }

        // Add epsilon transition from new initial state
        $stateOffset = 1;
        foreach ($l as $a) {
            if ($a->getNumStates() == 0) {
                continue;
            }
            $result->addEpsilon(0, $stateOffset);
            $stateOffset += $a->getNumStates();
        }

        $result->finishState();

        return self::removeDeadStates($result);
    }

    /**
     * Determinizes the given automaton.
     * <p>
     * Worst case complexity: exponential in number of states.
     *
     * @param maxDeterminizedStates Maximum number of states created when
     *   determinizing.  Higher numbers allow this operation to consume more
     *   memory but allow more complex automatons.  Use
     *   DEFAULT_MAX_DETERMINIZED_STATES as a decent default if you don't know
     *   how many to allow.
     *
     * @throws TooComplexToDeterminizeException if determinizing a creates an
     *   automaton with more than maxDeterminizedStates
     */
    public static function determinize(Automaton $a, int $maxDeterminizedStates): Automaton
    {
        if ($a->isDeterministic()) {
            // Already determinized
            return $a;
        }
        if ($a->getNumStates() <= 1) {
            // Already determinized
            return $a;
        }

        // subset construction
        $b = new Builder();

        //System.out.println("DET:");
        //$a->writeDot("/l/la/lucene/core/detin.dot");

        $initialset = new FrozenIntSet(0, 0);

        // Create state 0:
        $b->createState();

        $worklist = []; //new ArrayDeque<>();
        $newstate = []; //new HashMap<>();

        $worklist[] = $initialset;

        $b->setAccept(0, $a->isAccept(0));
        $newstate[(string)$initialset] = 0;

        // like Set<Integer,PointTransitions>
        $points = new PointTransitionSet();

        // like SortedMap<Integer,Integer>
        $statesSet = new SortedIntSet(5);

        $t = new Transition();

        while (count($worklist) > 0) {
            $s = array_shift($worklist);
            //System.out.println("det: pop set=" + s);

            // Collate all outgoing transitions by min/1+max:
            for ($i = 0; $i < count($s->values); $i++) {
                $s0 = $s->values[$i];
                $numTransitions = $a->getNumTransitions($s0);
                $a->initTransition($s0, $t);
                for ($j = 0; $j < $numTransitions; $j++) {
                    $a->getNextTransition($t);
                    $points[] = $t;
                }
            }

            if (count($points) == 0) {
                // No outgoing transitions -- skip it
                continue;
            }

            $points->sort();

            $lastPoint = -1;
            $accCount = 0;

            $r = $s->state;

            for ($i = 0; $i < count($points); $i++) {

                $point = $points->points[$i]->point;

                if ($statesSet->upto > 0) {
//            assert lastPoint != -1;
                    if (!($lastPoint != -1)) {
                        new \AssertionError('NOT: (lastPoint != -1)');
                    }

                    $statesSet->computeHash();

                    $q = $newstate[(string)$statesSet];
                    if ($q == null) {
                        $q = $b->createState();
                        if ($q >= $maxDeterminizedStates) {
                            throw new TooComplexToDeterminizeException($a, $maxDeterminizedStates);
                        }
                        $p = $statesSet->freeze($q);
                        //System->out->println("  make new state=" + q + " -> " + p + " accCount=" + accCount);
                        $worklist[] = $p;
                        $b->setAccept($q, $accCount > 0);
                        $newstate[(string)$p] = $q;
                    } else {
//              assert (accCount > 0 ? true:false) == b->isAccept(q): "accCount=" + accCount + " vs existing accept=" +
                        if (!(($accCount > 0 ? true : false) == $b->isAccept($q))) {
                            throw new \AssertionError("accCount=" . $accCount . " vs existing accept=" . $b->isAccept($q) . " states=" . $statesSet);
                        }
                    }

                    // System->out->println("  add trans src=" + r + " dest=" + q + " min=" + lastPoint + " max=" + (point-1));

                    $b->addTransition($r, $q, $lastPoint, $point - 1);
                }

                // process transitions that end on this point
                // (closes an overlapping interval)
                $transitions = $points->points[$i]->ends->transitions;
                $limit = $points->points[$i]->ends->next;
                for ($j = 0; $j < $limit; $j += 3) {
                    $dest = $transitions[$j];
                    $statesSet->decr($dest);
                    $accCount -= $a->isAccept($dest) ? 1 : 0;
                }
                $points->points[$i]->ends->next = 0;

                // process transitions that start on this point
                // (opens a new interval)
                $transitions = $points->points[$i]->starts->transitions;
                $limit = $points->points[$i]->starts->next;
                for ($j = 0; $j < $limit; $j += 3) {
                    $dest = $transitions[$j];
                    $statesSet->incr($dest);
                    $accCount += $a->isAccept($dest) ? 1 : 0;
                }
                $lastPoint = $point;
                $points->points[$i]->starts->next = 0;
            }
            $points->reset();
//      assert statesSet->upto == 0: "upto=" + statesSet->upto;
            if (!($statesSet->upto == 0)) {
                throw new \AssertionError("upto=" . $statesSet->upto);
            }
        }

        $result = $b->finish();
//    assert $result->isDeterministic();
        if (!$result->isDeterministic()) {
            throw new \AssertionError('NOT: ($result->isDeterministic())');
        }
        return $result;
    }

    /**
     * Returns true if the given automaton accepts no strings.
     */
    public static function isEmpty(Automaton $a): bool
    {
        if ($a->getNumStates() == 0) {
            // Common case: no states
            return true;
        }
        if ($a->isAccept(0) == false && $a->getNumTransitions(0) == 0) {
            // Common case: just one initial state
            return true;
        }
        if ($a->isAccept(0) == true) {
            // Apparently common case: it accepts the damned empty string
            return false;
        }

        $workList = []; //new ArrayDeque<>();
        $seen = new BitSet($a->getNumStates());
        $workList[] = 0;
        $seen->set(0);

        $t = new Transition();
        while (empty($workList) == false) {
            $state = array_shift($workList);
            if ($a->isAccept($state)) {
                return false;
            }
            $count = $a->initTransition($state, $t);
            for ($i = 0; $i < $count; $i++) {
                $a->getNextTransition($t);
                if ($seen->get($t->dest) == false) {
                    $workList[] = $t->dest;
                    $seen->set($t->dest);
                }
            }
        }

        return true;
    }

    /**
     * Returns true if the given automaton accepts all strings.  The automaton must be minimized.
     */
//  public static boolean isTotal(Automaton $a) {
//    return isTotal(a, \IntlChar::CODEPOINT_MIN, \IntlChar::CODEPOINT_MAX);
//}

    /**
     * Returns true if the given automaton accepts all strings for the specified min/max
     * range of the alphabet.  The automaton must be minimized.
     */
    public static function isTotal(Automaton $a, int $minAlphabet = \IntlChar::CODEPOINT_MIN, int $maxAlphabet = \IntlChar::CODEPOINT_MIN): bool
    {
        if ($a->isAccept(0) && $a->getNumTransitions(0) == 1) {
            $t = new Transition();
            $a->getTransition(0, 0, $t);
            return $t->dest == 0
                && $t->min == $minAlphabet
                && $t->max == $maxAlphabet;
        }
        return false;
    }

    /**
     * Returns true if the given string is accepted by the automaton.  The input must be deterministic.
     * <p>
     * Complexity: linear in the length of the string.
     * <p>
     * <b>Note:</b> for full performance, use the {@link RunAutomaton} class.
     */
    public static function run(Automaton $a, String $s): bool
    {
//    assert $a->isDeterministic();
        if (!$a->isDeterministic()) {
            throw new \AssertionError('NOT: ($a->isDeterministic())');
        }
        $state = 0;
        for ($i = 0, $cp = 0; $i < mb_strlen($s); $i += mb_strlen(\IntlChar::chr($cp))) {
            $nextState = $a->step($state, $cp = \IntlChar::ord(mb_substr($s, $i, 1)));
            if ($nextState == -1) {
                return false;
            }
            $state = $nextState;
        }
        return $a->isAccept($state);
    }

    /**
     * Returns true if the given string (expressed as unicode codepoints) is accepted by the automaton.  The input must be deterministic.
     * <p>
     * Complexity: linear in the length of the string.
     * <p>
     * <b>Note:</b> for full performance, use the {@link RunAutomaton} class.
     */
//  public static function run(Automaton $a, IntsRef $s): bool {
//    assert $a->isDeterministic();
//    int state = 0;
//    for (int i=0;i<s.length;i++) {
//        int nextState = $a->step(state, s.ints[s.offset+i]);
//      if (nextState == -1) {
//          return false;
//      }
//      state = nextState;
//    }
//    return $a->isAccept(state);
//  }

    /**
     * Returns the set of live states. A state is "live" if an accept state is
     * reachable from it and if it is reachable from the initial state.
     */
    private static function getLiveStates(Automaton $a): BitSet
    {
        $live = self::getLiveStatesFromInitial($a);
        $live->and(self::getLiveStatesToAccept($a));
        return $live;
    }

    /** Returns bitset marking states reachable from the initial state. */
    private static function getLiveStatesFromInitial(Automaton $a): BitSet
    {
        $numStates = $a->getNumStates();
        $live = new BitSet($numStates);
        if ($numStates == 0) {
            return $live;
        }
        $workList = [];
        $live->set(0);
        $workList[] = 0;

        $t = new Transition();
        while (empty($workList) == false) {
            $s = array_shift($workList);
            $count = $a->initTransition($s, $t);
            for ($i = 0; $i < $count; $i++) {
                $a->getNextTransition($t);
                if ($live->get($t->dest) == false) {
                    $live->set($t->dest);
                    $workList[] = $t->dest;
                }
            }
        }

        return $live;
    }

    /** Returns bitset marking states that can reach an accept state. */
    private static function getLiveStatesToAccept(Automaton $a): BitSet
    {
        $builder = new Builder();

        // NOTE: not quite the same thing as what SpecialOperations.reverse does:
        $t = new Transition();
        $numStates = $a->getNumStates();
        for ($s = 0; $s < $numStates; $s++) {
            $builder->createState();
        }
        for ($s = 0; $s < $numStates; $s++) {
            $count = $a->initTransition($s, $t);
            for ($i = 0; $i < $count; $i++) {
                $a->getNextTransition($t);
                $builder->addTransition($t->dest, $s, $t->min, $t->max);
            }
        }
        $a2 = $builder->finish();

        $workList = []; //new ArrayDeque<>();
        $live = new BitSet($numStates);
        $acceptBits = $a->getAcceptStates();
        $s = 0;
        while ($s < $numStates && ($s = $acceptBits->nextSetBit($s)) != -1) {
            $live->set($s);
            $workList[] = $s;
            $s++;
        }

        while (empty($workList) == false) {
            $s = array_shift($workList);
            $count = $a2->initTransition($s, $t);
            for ($i = 0; $i < $count; $i++) {
                $a2->getNextTransition($t);
                if ($live->get($t->dest) == false) {
                    $live->set($t->dest);
                    $workList[] = $t->dest;
                }
            }
        }

        return $live;
    }

    /**
     * Removes transitions to dead states (a state is "dead" if it is not
     * reachable from the initial state or no accept state is reachable from it.)
     */
    public static function removeDeadStates(Automaton $a): Automaton
    {
        $numStates = $a->getNumStates();
        $liveSet = self::getLiveStates($a);

        $map = []; //new int[numStates];

        $result = new Automaton();
        //System->out->println("liveSet: " + liveSet + " numStates=" + numStates);
        for ($i = 0; $i < $numStates; $i++) {
            if ($liveSet->get($i)) {
                $map[$i] = $result->createState();
                $result->setAccept($map[$i], $a->isAccept($i));
            }
        }

        $t = new Transition();

        for ($i = 0; $i < $numStates; $i++) {
            if ($liveSet->get($i)) {
                $numTransitions = $a->initTransition($i, $t);
                // filter out transitions to dead states:
                for ($j = 0; $j < $numTransitions; $j++) {
                    $a->getNextTransition($t);
                    if ($liveSet->get($t->dest)) {
                        $result->addTransition($map[$i], $map[$t->dest], $t->min, $t->max);
                    }
                }
            }
        }

        $result->finishState();
//    assert hasDeadStates(result) == false;
        if (!(self::hasDeadStates($result) == false)) {
            throw new \AssertionError('NOT: (hasDeadStates(result) == false)');
        }
        return $result;
    }

    /**
     * Returns true if the language of this automaton is finite.  The
     * automaton must not have any dead states.
     */
    public static function isFinite(Automaton $a): bool
    {
        if ($a->getNumStates() == 0) {
            return true;
        }
        return self::isFiniteByParams(new Transition(), $a, 0, new BitSet($a->getNumStates()), new BitSet($a->getNumStates()));
    }

    /**
     * Checks whether there is a loop containing state. (This is sufficient since
     * there are never transitions to dead states.)
     */
    // TODO: not great that this is recursive... in theory a
    // large automata could exceed java's stack
    private static function isFiniteByParams(Transition $scratch, Automaton $a, int $state, BitSet $path, BitSet $visited): bool
    {
        $path->set($state);
        $numTransitions = $a->initTransition($state, $scratch);
        for ($t = 0; $t < $numTransitions; $t++) {
            $a->getTransition($state, $t, $scratch);
            if ($path->get($scratch->dest) || (!$visited->get($scratch->dest) && !self::isFiniteByParams($scratch, $a, $scratch->dest, $path, $visited))) {
                return false;
            }
        }
        $path->clear($state);
        $visited->set($state);
        return true;
    }

    /**
     * Returns the longest string that is a prefix of all accepted strings and
     * visits each state at most once.  The automaton must be deterministic.
     *
     * @return common prefix, which can be an empty (length 0) String (never null)
     */
    public static function getCommonPrefix(Automaton $a): string
    {
        if ($a->isDeterministic() == false) {
            throw new IllegalArgumentException("input automaton must be deterministic");
        }
        $b = '';
        $visited = []; //new HashSet<>();
        $s = 0;
        $t = new Transition();
        do {
            $done = true;
            $visited[] = $s;
            if ($a->isAccept($s) == false && $a->getNumTransitions($s) == 1) {
                $a->getTransition($s, 0, $t);
                if ($t->min == $t->max && !in_array($t->dest, $visited)) {
                    $b .= \IntlChar::chr($t->min);
                    $s = $t->dest;
                    $done = false;
                }
            }
        } while (!$done);

        return $b;
    }

    // TODO: this currently requites a determinized machine,
    // but it need not -- we can speed it up by walking the
    // NFA instead.  it'd still be fail fast.
    /**
     * Returns the longest BytesRef that is a prefix of all accepted strings and
     * visits each state at most once.  The automaton must be deterministic.
     *
     * @return common prefix, which can be an empty (length 0) BytesRef (never null)
     */
//  public static BytesRef getCommonPrefixBytesRef(Automaton $a) {
//    BytesRefBuilder builder = new BytesRefBuilder();
//    HashSet<Integer> visited = new HashSet<>();
//    int s = 0;
//    boolean done;
//    Transition t = new Transition();
//    do {
//        done = true;
//        visited.add(s);
//        if ($a->isAccept(s) == false && $a->getNumTransitions(s) == 1) {
//            $a->getTransition(s, 0, t);
//            if (t.min == $t->max && !visited.contains(t.dest)) {
//                builder.append((byte) $t->min);
//          s = $t->dest;
//          done = false;
//        }
//        }
//    } while (!done);
//
//    return builder.get();
//  }

    /** If this automaton accepts a single input, return it.  Else, return null.
     *  The automaton must be deterministic. */
//  public static IntsRef getSingleton(Automaton $a) {
//    if ($a->isDeterministic() == false) {
//        throw new IllegalArgumentException("input automaton must be deterministic");
//    }
//    IntsRefBuilder builder = new IntsRefBuilder();
//    HashSet<Integer> visited = new HashSet<>();
//    int s = 0;
//    Transition t = new Transition();
//    while (true) {
//        visited.add(s);
//        if ($a->isAccept(s) == false) {
//            if ($a->getNumTransitions(s) == 1) {
//                $a->getTransition(s, 0, t);
//                if (t.min == $t->max && !visited.contains(t.dest)) {
//                    builder.append(t.min);
//                    s = $t->dest;
//                    continue;
//                }
//            }
//        } else if ($a->getNumTransitions(s) == 0) {
//            return builder.get();
//        }
//
//        // Automaton accepts more than one string:
//        return null;
//    }
//  }

    /**
     * Returns the longest BytesRef that is a suffix of all accepted strings.
     * Worst case complexity: exponential in number of states (this calls
     * determinize).
     *
     * @param maxDeterminizedStates maximum number of states determinizing the
     *  automaton can result in.  Set higher to allow more complex queries and
     *  lower to prevent memory exhaustion.
     *
     * @return common suffix, which can be an empty (length 0) BytesRef (never null)
     */
//  public static BytesRef getCommonSuffixBytesRef(Automaton $a, int maxDeterminizedStates) {
//    // reverse the language of the automaton, then reverse its common prefix.
//    Automaton r = Operations.determinize(reverse($a), maxDeterminizedStates);
//    BytesRef ref = getCommonPrefixBytesRef(r);
//    reverseBytes(ref);
//    return ref;
//  }

//  private static void reverseBytes(BytesRef ref) {
//    if (ref.length <= 1) return;
//    int num = ref.length >> 1;
//    for (int i = ref.offset; i < ( ref.offset + num ); i++) {
//        byte b = ref.bytes[i];
//      ref.bytes[i] = ref.bytes[ref.offset * 2 + ref.length - i - 1];
//      ref.bytes[ref.offset * 2 + ref.length - i - 1] = b;
//    }
//  }

    /** Returns an automaton accepting the reverse language. */
//  public static Automaton reverse(Automaton $a) {
//    return reverse(a, null);
//}

    /** Reverses the automaton, returning the new initial states. */
//  static Automaton reverse(Automaton $a, Set<Integer> initialStates) {
//
//    if (Operations.isEmpty($a)) {
//        return new Automaton();
//    }
//
//    int numStates = $a->getNumStates();
//
//    // Build a new automaton with all edges reversed
//    Automaton.Builder builder = new Automaton.Builder();
//
//    // Initial node; we'll add epsilon transitions in the end:
//    builder.createState();
//
//    for(int s=0;s<numStates;s++) {
//        builder.createState();
//    }
//
//    // Old initial state becomes new accept state:
//    builder.setAccept(1, true);
//
//    Transition t = new Transition();
//    for (int s=0;s<numStates;s++) {
//        int numTransitions = $a->getNumTransitions(s);
//      $a->initTransition(s, t);
//      for(int i=0;i<numTransitions;i++) {
//            $a->getNextTransition(t);
//            builder.addTransition(t.dest+1, s+1, $t->min, $t->max);
//        }
//    }
//
//    Automaton result = builder.finish();
//
//    int s = 0;
//    BitSet acceptStates = $a->getAcceptStates();
//    while (s < numStates && (s = acceptStates.nextSetBit(s)) != -1) {
//        $result->addEpsilon(0, s+1);
//        if (initialStates != null) {
//            initialStates.add(s+1);
//        }
//        s++;
//    }
//
//    $result->finishState();
//
//    return result;
//  }

    /** Returns a new automaton accepting the same language with added
     *  transitions to a dead state so that from every state and every label
     *  there is a transition. */
    static public function totalize(Automaton $a): Automaton
    {
        error_log('totalize');
        $result = new Automaton();
        $numStates = $a->getNumStates();
        for ($i = 0; $i < $numStates; $i++) {
            $result->createState();
            $result->setAccept($i, $a->isAccept($i));
        }

        $deadState = $result->createState();
        $result->addTransition($deadState, $deadState, \IntlChar::CODEPOINT_MIN, \IntlChar::CODEPOINT_MAX);

        $t = new Transition();
        for ($i = 0; $i < $numStates; $i++) {
            $maxi = \IntlChar::CODEPOINT_MIN;
            $count = $a->initTransition($i, $t);
            for ($j = 0; $j < $count; $j++) {
                $a->getNextTransition($t);
                $result->addTransition($i, $t->dest, $t->min, $t->max);
                if ($t->min > $maxi) {
                    $result->addTransition($i, $deadState, $maxi, $t->min - 1);
                }
                if ($t->max + 1 > $maxi) {
                    $maxi = $t->max + 1;
                }
            }

            if ($maxi <= \IntlChar::CODEPOINT_MAX) {
                $result->addTransition($i, $deadState, $maxi, \IntlChar::CODEPOINT_MAX);
            }
        }

        $result->finishState();
        return $result;
    }

    /** Returns the topological sort of all states reachable from
     *  the initial state.  Behavior is undefined if this
     *  automaton has cycles.  CPU cost is O(numTransitions),
     *  and the implementation is recursive so an automaton
     *  matching long strings may exhaust the java stack. */
    public static function topoSortStates(Automaton $a): array
    {
        if ($a->getNumStates() == 0) {
            return []; //new int[0];
        }
        $numStates = $a->getNumStates();
        $states = []; //new int[numStates];
        $visited = new BitSet($numStates);
        $upto = self::topoSortStatesRecurse($a, $visited, $states, 0, 0);

        if ($upto < count($states)) {
            // There were dead states
            $newStates = []; //new int[upto];
            Util::arraycopy($states, 0, $newStates, 0, $upto);
            $states = $newStates;
        }

        // Reverse the order:
        for ($i = 0; $i < count($states) / 2; $i++) {
            $s = $states[$i];
            $states[$i] = $states[count($states) - 1 - $i];
            $states[count($states) - 1 - $i] = $s;
        }

        return $states;
    }

    private static function topoSortStatesRecurse(Automaton $a, BitSet $visited, &$states, int $upto, int $state): int
    {
        $t = new Transition();
        $count = $a->initTransition($state, $t);
        for ($i = 0; $i < $count; $i++) {
            $a->getNextTransition($t);
            if (!$visited->get($t->dest)) {
                $visited->set($t->dest);
                $upto = self::topoSortStatesRecurse($a, $visited, $states, $upto, $t->dest);
            }
        }
        $states[$upto] = $state;
        $upto++;
        return $upto;
    }
}


// Simple custom ArrayList<Transition>
final class TransitionList
{
    // dest, min, max
    public $transitions = []; //new int[3];
    public $next = 0;

    public function add(Transition $t)
    {
        if (count($this->transitions) < $this->next + 3) {
            $this->transitions = Util::growIntArray($this->transitions, $this->next + 3);
        }
        $this->transitions[$this->next] = $t->dest;
        $this->transitions[$this->next + 1] = $t->min;
        $this->transitions[$this->next + 2] = $t->max;
        $this->next += 3;
    }
}

// Holds all transitions that start on this int point, or
// end at this point-1
final class PointTransitions
{
    public $point;
    /** @var TransitionList */
    public $ends = null; //new TransitionList();
    /** @var TransitionList */
    public $starts = null; //new TransitionList();

    public function __construct()
    {
        $this->ends = new TransitionList();
        $this->starts = new TransitionList();
    }

    public function compareTo(PointTransitions $other): int
    {
        return $this->point - $other->point;
    }

    public function reset(int $point)
    {
        $this->point = $point;
        $this->ends->next = 0;
        $this->starts->next = 0;
    }

    public function equals(PointTransitions $other): bool
    {
        return $other->point == $this->point;
    }

    public function hashCode(): int
    {
        return $this->point;
    }
}

final class PointTransitionSet implements \Countable
{
    public $count = 0;
    /** @var PointTransitions[] */
    public $points = []; //new PointTransitions[5];

    const HASHMAP_CUTOVER = 30;
    public $map = [];
    public $useHash = false;

    private function next(int $point): PointTransitions
    {
        // 1st time we are seeing this point
//      if ($this->count == count($this->points)) {
//        $newArray = new PointTransitions[ArrayUtil.oversize(1+count, RamUsageEstimator.NUM_BYTES_OBJECT_REF)];
//        System.arraycopy(points, 0, newArray, 0, count);
//        points = newArray;
//      }
        $points0 = isset($this->points[$this->count]) ? $this->points[$this->count] : null;
        if ($points0 == null) {
            $points0 = $this->points[$this->count] = new PointTransitions();
        }
        $points0->reset($point);
        $this->count++;
        return $points0;
    }

    private function find(int $point): PointTransitions
    {
        if ($this->useHash) {
            $pi = $point;
            $p = $this->map[$pi] ?? null;
            if ($p == null) {
                $p = $this->next($point);
                $this->map[$pi] = $p;
            }
            return $p;
        } else {
            for ($i = 0; $i < $this->count; $i++) {
                if ($this->points[$i]->point == $point) {
                    return $this->points[$i];
                }
            }

            $p = $this->next($point);
            if ($this->count == self::HASHMAP_CUTOVER) {
                // switch to HashMap on the fly
//            assert map.size() == 0;
                if (!(count($this->map) == 0)) {
                    throw new \AssertionError('NOT: map.size() == 0');
                }
                for ($i = 0; $i < $this->count; $i++) {
                    $this->map[$this->points[$i]->point] = $this->points[$i];
                }
                $this->useHash = true;
            }
            return $p;
        }
    }

    public function reset()
    {
        if ($this->useHash) {
            $this->map = [];
            $this->useHash = false;
        }
        $this->count = 0;
    }

    public function sort()
    {
        // Tim sort performs well on already sorted arrays:
        if ($this->count > 1) {
            sort($this->points);
        }
    }

    public function add(Transition $t)
    {
        $this->find($t->min)->starts->add($t);
        $this->find(1 + $t->max)->ends->add($t);
    }

//    public String toString() {
//StringBuilder s = new StringBuilder();
//      for(int i=0;i<count;i++) {
//    if (i > 0) {
//        s.append(' ');
//    }
//    s.append(points[i].point).append(':').append(points[i].starts.next/3).append(',').append(points[i].ends.next/3);
//}
//      return s.toString();
//    }
    /**
     * Count elements of an object
     * @link  https://php.net/manual/en/countable.count.php
     * @return int The custom count as an integer.
     * </p>
     * <p>
     * The return value is cast to an integer.
     * @since 5.1.0
     */
    public function count()
    {
        return $this->count;
    }
}
