<?php

namespace ftIndex\util\automaton;

use ftIndex\analyses\hunspell\BytesRef;
use ftIndex\analyses\hunspell\IllegalArgumentException;
use ftIndex\util\StringHelper;

/**
 * Class Automata
 *
 * @package ftIndex\util\automaton
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/25/19 1:15 PM
 */
final class Automata
{

    private function __construct()
    {
    }

    /**
     * Returns a new (deterministic) automaton with the empty language.
     */
    public static function makeEmpty(): Automaton
    {
        $a = new Automaton();
        $a->finishState();
        return $a;
    }

    /**
     * Returns a new (deterministic) automaton that accepts only the empty string.
     */
    public static function makeEmptyString(): Automaton
    {
        $a = new Automaton();
        $a->createState();
        $a->setAccept(0, true);
        return $a;
    }

    /**
     * Returns a new (deterministic) automaton that accepts all strings.
     */
    public static function makeAnyString(): Automaton
    {
        $a = new Automaton();
        $s = $a->createState();
        $a->setAccept($s, true);
        $a->addTransition($s, $s, \IntlChar::CODEPOINT_MIN, \IntlChar::CODEPOINT_MAX);
        $a->finishState();
        return $a;
    }

    /**
     * Returns a new (deterministic) automaton that accepts all binary terms.
     */
    public static function makeAnyBinary(): Automaton
    {
        $a = new Automaton();
        $s = $a->createState();
        $a->setAccept($s, true);
        $a->addTransition($s, $s, 0, 255);
        $a->finishState();
        return $a;
    }

    /**
     * Returns a new (deterministic) automaton that accepts any single codepoint.
     */
    public static function makeAnyChar(): Automaton
    {
        return self::makeCharRange(\IntlChar::CODEPOINT_MIN, \IntlChar::CODEPOINT_MAX);
    }

    /** Accept any single character starting from the specified state, returning the new state */
    public static function appendAnyChar(Automaton $a, int $state): int
    {
        $newState = $a->createState();
        $a->addTransition($state, $newState, \IntlChar::CODEPOINT_MIN, \IntlChar::CODEPOINT_MAX);
        return $newState;
    }

    /**
     * Returns a new (deterministic) automaton that accepts a single codepoint of
     * the given value.
     */
    public static function makeChar(int $c): Automaton
    {
        return self::makeCharRange($c, $c);
    }

    /** Appends the specified character to the specified state, returning a new state. */
    public static function appendChar(Automaton $a, int $state, int $c): int
    {
        $newState = $a->createState();
        $a->addTransition($state, $newState, $c, $c);
        return $newState;
    }

    /**
     * Returns a new (deterministic) automaton that accepts a single codepoint whose
     * value is in the given interval (including both end points).
     */
    public static function makeCharRange(int $min, int $max): Automaton
    {
        if ($min > $max) {
            return self::makeEmpty();
        }
        $a = new Automaton();
        $s1 = $a->createState();
        $s2 = $a->createState();
        $a->setAccept($s2, true);
        $a->addTransition($s1, $s2, $min, $max);
        $a->finishState();
        return $a;
    }

    /**
     * Constructs sub-automaton corresponding to decimal numbers of length
     * x.substring(n).length().
     */
    private static function anyOfRightLength(Builder $builder, string $x, int $n): int
    {
        $s = $builder->createState();
        if (mb_strlen($x) == $n) {
            $builder->setAccept($s, true);
        } else {
            $builder->addTransition($s, self::anyOfRightLength($builder, $x, $n + 1), '0', '9');
        }
        return $s;
    }

    /**
     * Constructs sub-automaton corresponding to decimal numbers of value at least
     * x.substring(n) and length x.substring(n).length().
     */
    private static function atLeast(Builder $builder, string $x, int $n, $initials, bool $zeros): int
    {
        $s = $builder->createState();
        if (mb_strlen($x) == $n) {
            $builder->setAccept($s, true);
        } else {
            if ($zeros) {
                $initials[] = $s;
            }
            $c = mb_substr($x, $n, 1);
            $builder->addTransition($s, self::atLeast($builder, $x, $n + 1, $initials, $zeros && $c == '0'), $c);
            if ($c < '9') {
                $builder->addTransition($s, self::anyOfRightLength($builder, $x, $n + 1), \IntlChar::chr(\IntlChar::ord($c) + 1), '9');
            }
        }
        return $s;
    }

    /**
     * Constructs sub-automaton corresponding to decimal numbers of value at most
     * x.substring(n) and length x.substring(n).length().
     */
    private static function atMost(Builder $builder, string $x, int $n): int
    {
        $s = $builder->createState();
        if (mb_strlen($x) == $n) {
            $builder->setAccept($s, true);
        } else {
            $c = mb_substr($x, $n, 1);
            $builder->addTransition($s, self::atMost($builder, $x, \IntlChar::chr($n + 1)), $c);
            if ($c > '0') {
                $builder->addTransition($s, self::anyOfRightLength($builder, $x, $n + 1), '0', \IntlChar::chr(\IntlChar::ord($c) - 1));
            }
        }
        return $s;
    }

    /**
     * Constructs sub-automaton corresponding to decimal numbers of value between
     * x.substring(n) and y.substring(n) and of length x.substring(n).length()
     * (which must be equal to y.substring(n).length()).
     */
    private static function between(Builder $builder, string $x, string $y, int $n, $initials, bool $zeros): int
    {
        $s = $builder->createState();
        if (mb_strlen($x) == $n) {
            $builder->setAccept($s, true);
        } else {
            if ($zeros) {
                $initials[] = $s;
            }
            $cx = mb_substr($x, $n, 1);
            $cy = mb_substr($y, $n, 1);
            if ($cx == $cy) {
                $builder->addTransition($s, self::between($builder, $x, $y, $n + 1, $initials, $zeros && $cx == '0'), $cx);
            } else { // cx<cy
                $builder->addTransition($s, self::atLeast($builder, $x, $n + 1, $initials, $zeros && $cx == '0'), $cx);
                $builder->addTransition($s, self::atMost($builder, $y, $n + 1), $cy);
                if (\IntlChar::ord($cx) + 1 < \IntlChar::ord($cy)) {
                    $builder->addTransition($s, self::anyOfRightLength($builder, $x, $n + 1), \IntlChar::chr(\IntlChar::ord($cx) + 1), \IntlChar::chr(\IntlChar::ord($cy) - 1));
                }
            }
        }

        return $s;
    }

    private static function suffixIsZeros(BytesRef $br, int $len): bool
    {
        for ($i = $len; $i < $br->length; $i++) {
            if ($br->bytes[$br->offset + $i] != 0) {
                return false;
            }
        }

        return true;
    }

    /** Creates a new deterministic, minimal $accepting
     *  all binary terms in the specified interval.  Note that unlike
     *  {@link #makeDecimalInterval}, the returned automaton is infinite,
     *  because terms behave like floating point numbers leading with
     *  a decimal point.  However, in the special case where min == max,
     *  and both are inclusive, the automata will be finite and accept
     *  exactly one term. */
    public static function makeBinaryInterval(BytesRef $min, bool $minInclusive, BytesRef $max, bool $maxInclusive): Automaton
    {

        if ($min == null && $minInclusive == false) {
            throw new IllegalArgumentException("minInclusive must be true when min is null (open ended)");
        }

        if ($max == null && $maxInclusive == false) {
            throw new IllegalArgumentException("maxInclusive must be true when max is null (open ended)");
        }

        if ($min == null) {
            $min = new BytesRef();
            $minInclusive = true;
        }

        if ($max != null) {
            $cmp = $min->compareTo($max);
        } else {
            $cmp = -1;
            if ($min->length == 0 && $minInclusive) {
                return self::makeAnyBinary();
            }
        }

        if ($cmp == 0) {
            if ($minInclusive == false || $maxInclusive == false) {
                return self::makeEmpty();
            } else {
                return self::makeBinary($min);
            }
        } else {
            if ($cmp > 0) {
                // max > min
                return self::makeEmpty();
            }
        }

        if ($max != null &&
            StringHelper::startsWith($max, $min) &&
            self::suffixIsZeros($max, $min->length)) {

            // Finite case: no sink state!

            $maxLength = $max->length;

            // the == case was handled above
//      assert $maxLength > $min->length;
            if (!($maxLength > $min->length)) {
                throw new \AssertionError('NOT: ($maxLength > $min->length)');
            }

            //  bar -> bar\0+
            if ($maxInclusive == false) {
                $maxLength--;
            }

            if ($maxLength == $min->length) {
                if ($minInclusive == false) {
                    return self::makeEmpty();
                } else {
                    return self::makeBinary($min);
                }
            }

            $a = new Automaton();
            $lastState = $a->createState();
            for ($i = 0; $i < $min->length; $i++) {
                $state = $a->createState();
                $label = $min->bytes[$min->offset + $i] & 0xff;
                $a->addTransition($lastState, $state, $label);
                $lastState = $state;
            }

            if ($minInclusive) {
                $a->setAccept($lastState, true);
            }

            for ($i = $min->length; $i < $maxLength; $i++) {
                $state = $a->createState();
                $a->addTransition($lastState, $state, 0);
                $a->setAccept($state, true);
                $lastState = $state;
            }
            $a->finishState();
            return $a;
        }

        $a = new Automaton();
        $startState = $a->createState();

        $sinkState = $a->createState();
        $a->setAccept($sinkState, true);

        // This state accepts all suffixes:
        $a->addTransition($sinkState, $sinkState, 0, 255);

        $equalPrefix = true;
        $lastState = $startState;
        $firstMaxState = -1;
        $sharedPrefixLength = 0;
        for ($i = 0; $i < $min->length; $i++) {
            $minLabel = $min->bytes[$min->offset + $i] & 0xff;

            if ($max != null && $equalPrefix && $i < $max->length) {
                $maxLabel = $max->bytes[$max->offset + $i] & 0xff;
            } else {
                $maxLabel = -1;
            }

            if ($minInclusive && $i == $min->length - 1 && ($equalPrefix == false || $minLabel != $maxLabel)) {
                $nextState = $sinkState;
            } else {
                $nextState = $a->createState();
            }

            if ($equalPrefix) {

                if ($minLabel == $maxLabel) {
                    // Still in shared prefix
                    $a->addTransition($lastState, $nextState, $minLabel);
                } else {
                    if ($max == null) {
                        $equalPrefix = false;
                        $sharedPrefixLength = 0;
                        $a->addTransition($lastState, $sinkState, $minLabel + 1, 0xff);
                        $a->addTransition($lastState, $nextState, $minLabel);
                    } else {
                        // This is the first point where min & max diverge:
//              assert $maxLabel > $minLabel;
                        if (!($maxLabel > $minLabel)) {
                            throw new \AssertionError('NOT: ($maxLabel > $minLabel)');
                        }

                        $a->addTransition($lastState, $nextState, $minLabel);

                        if ($maxLabel > $minLabel + 1) {
                            $a->addTransition($lastState, $sinkState, $minLabel + 1, $maxLabel - 1);
                        }

                        // Now fork off path for max:
                        if ($maxInclusive || i < $max->length - 1) {
                            $firstMaxState = $a->createState();
                            if ($i < $max->length - 1) {
                                $a->setAccept($firstMaxState, true);
                            }
                            $a->addTransition($lastState, $firstMaxState, $maxLabel);
                        }
                        $equalPrefix = false;
                        $sharedPrefixLength = $i;
                    }
                }
            } else {
                // OK, already diverged:
                $a->addTransition($lastState, $nextState, $minLabel);
                if ($minLabel < 255) {
                    $a->addTransition($lastState, $sinkState, $minLabel + 1, 255);
                }
            }
            $lastState = $nextState;
        }

        // Accept any suffix appended to the min term:
        if ($equalPrefix == false && $lastState != $sinkState && $lastState != $startState) {
            $a->addTransition($lastState, $sinkState, 0, 255);
        }

        if ($minInclusive) {
            // Accept exactly the min term:
            $a->setAccept($lastState, true);
        }

        if ($max != null) {

            // Now do max:
            if ($firstMaxState == -1) {
                // Min was a full prefix of max
                $sharedPrefixLength = $min->length;
            } else {
                $lastState = $firstMaxState;
                $sharedPrefixLength++;
            }
            for ($i = $sharedPrefixLength; $i < $max->length; $i++) {
                $maxLabel = $max->bytes[$max->offset + $i] & 0xff;
                if ($maxLabel > 0) {
                    $a->addTransition($lastState, $sinkState, 0, $maxLabel - 1);
                }
                if ($maxInclusive || $i < $max->length - 1) {
                    $nextState = $a->createState();
                    if ($i < $max->length - 1) {
                        $a->setAccept($nextState, true);
                    }
                    $a->addTransition($lastState, $nextState, $maxLabel);
                    $lastState = $nextState;
                }
            }

            if ($maxInclusive) {
                $a->setAccept($lastState, true);
            }
        }

        $a->finishState();

//    assert $a->isDeterministic(): $a->toDot();
        if (!$a->isDeterministic()) {
            throw new \AssertionError($a->toDot());
        }

        return $a;
    }

    /**
     * Returns a new automaton that accepts strings representing decimal (base 10)
     * non-negative integers in the given interval.
     *
     * @param min minimal value of interval
     * @param max maximal value of interval (both end points are included in the
     *          interval)
     * @param digits if &gt; 0, use fixed number of digits (strings must be prefixed
     *          by 0's to obtain the right length) - otherwise, the number of
     *          digits is not fixed (any number of leading 0s is accepted)
     *
     * @exception IllegalArgumentException if min &gt; max or if numbers in the
     *              interval cannot be expressed with the given fixed number of
     *              digits
     */
    public static function makeDecimalInterval(int $min, int $max, int $digits): Automaton
    {
        $x = (string)($min);
        $y = (string)($max);
        if ($min > $max || ($digits > 0 && mb_strlen($y) > $digits)) {
            throw new IllegalArgumentException();
        }
        if ($digits > 0) {
            $d = $digits;
        } else {
            $d = mb_strlen($y);
        }
        $bx = '';
        for ($i = mb_strlen($x); $i < $d; $i++) {
            $bx .= '0';
        }
        $bx .= $x;
        $x = $bx;
        $by = '';
        for ($i = mb_strlen($y); $i < $d; $i++) {
            $by .= '0';
        }
        $by .= $y;
        $y = $by;

        $builder = new Builder();

        if ($digits <= 0) {
            // Reserve the "real" initial state:
            $builder->createState();
        }

        $initials = []; //new ArrayList<>();

        self::between($builder, $x, $y, 0, $initials, $digits <= 0);

        $a1 = $builder->finish();

        if ($digits <= 0) {
            $a1->addTransition(0, 0, '0');
            foreach ($initials as $p) {
                $a1->addEpsilon(0, $p);
            }
            $a1->finishState();
        }

        return $a1;
    }

    /**
     * Returns a new (deterministic) automaton that accepts the single given
     * string.
     */
    public static function makeString(string $s): Automaton
    {
        $a = new Automaton();
        $lastState = $a->createState();
        for ($i = 0; $i < mb_strlen($s); $i += mb_strlen(\IntlChar::chr($cp))) {
            $state = $a->createState();
            $cp = \IntlChar::ord(mb_substr($s, $i, 1));
            $a->addTransition($lastState, $state, $cp);
            $lastState = $state;
        }

        $a->setAccept($lastState, true);
        $a->finishState();

//    assert $a->isDeterministic();
        if (!($a->isDeterministic())) {
            throw new \AssertionError('NOT: ($a->isDeterministic())');
        }
//    assert Operations.hasDeadStates(a) == false;
//        if (!(Operations::hasDeadStates($a) == false)) {
//            throw new \AssertionError('NOT: (Operations.hasDeadStates(a) == false)');
//        }

        return $a;
    }

    /**
     * Returns a new (deterministic) automaton that accepts the single given
     * binary term.
     */
    public static function makeBinary(BytesRef $term): Automaton
    {
        $a = new Automaton();
        $lastState = $a->createState();
        for ($i = 0; $i < $term->length; $i++) {
            $state = $a->createState();
            $label = $term->bytes[$term->offset + i] & 0xff;
            $a->addTransition($lastState, $state, $label);
            $lastState = $state;
        }

        $a->setAccept($lastState, true);
        $a->finishState();

//    assert $a->isDeterministic();
        if (!($a->isDeterministic())) {
            throw new \AssertionError('NOT: ($a->isDeterministic())');
        }
//    assert Operations.hasDeadStates(a) == false;
        if (!(Operations::hasDeadStates($a) == false)) {
            throw new \AssertionError('NOT: (Operations.hasDeadStates(a) == false)');
        }

        return $a;
    }

    /**
     * Returns a new (deterministic) automaton that accepts the single given
     * string from the specified unicode code points.
     */
    public static function makeStringByOffsetLength($word, int $offset, int $length): Automaton
    {
        $a = new Automaton();
        $a->createState();
        $s = 0;
        for ($i = $offset; $i < $offset + $length; $i++) {
            $s2 = $a->createState();
            $a->addTransition($s, $s2, mb_substr($word, $i, 1));
            $s = $s2;
        }
        $a->setAccept($s, true);
        $a->finishState();

        return $a;
    }

    /**
     * Returns a new (deterministic and minimal) automaton that accepts the union
     * of the given collection of {@link BytesRef}s representing UTF-8 encoded
     * strings.
     *
     * @param BytesRef[] $utf8Strings
     *          The input strings, UTF-8 encoded. The collection must be in sorted
     *          order.
     *
     * @return Automaton An {@link Automaton} accepting all input strings. The resulting
     *         automaton is codepoint based (full unicode codepoints on
     *         transitions).
     */
    public static function makeStringUnion($utf8Strings): Automaton
    {
        if (empty($utf8Strings)) {
            return self::makeEmpty();
        } else {
            return DaciukMihovAutomatonBuilder::build($utf8Strings);
        }
    }
}
