<?php

namespace ftIndex\util\automaton;

use ftIndex\analyses\hunspell\IllegalArgumentException;
use ftIndex\analyses\hunspell\IOException;

/**
 * Class RegExp
 *
 * @package ftIndex\util\automaton
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/20/19 8:12 PM
 */
class RegExp
{
    const INTERSECTION = 1;
    const COMPLEMENT   = 2;
    const EMPTY        = 4;
    const ANYSTRING    = 8;
    const AUTOMATON    = 16;
    const INTERVAL     = 32;
    const ALL          = 65535;
    const NONE         = 0;
    private $originalString;
    /** @var int */
    private $kind;
    /** @var RegExp */
    private $exp1;
    /** @var RegExp */
    private $exp2;
    /** @var string */
    private $s;
    /** @var int */
    private $c;
    /** @var int */
    private $min;
    /** @var int */
    private $max;
    /** @var int */
    private $digits;
    /** @var int */
    private $from;
    /** @var int */
    private $to;
    /** @var int */
    private $flags;
    /** @var int */
    private $pos;


    public function __construct($s = null, $syntax_flags = 65535)
    {
        if ($s === null) {
            $this->originalString = null;

            return;
        }

        $this->originalString = $s;
        $this->flags = $syntax_flags;
        if ($s === null || mb_strlen($s) === 0) {
            /** @var RegExp $e */
            $e = $this->makeString('');
        } else {
            $e = $this->parseUnionExp();
            if ($this->pos < mb_strlen($this->originalString)) {
                throw new IllegalArgumentException("end-of-string expected at position " . $this->pos);
            }
        }

        $this->kind = $e->kind;
        $this->exp1 = $e->exp1;
        $this->exp2 = $e->exp2;
        $this->s = $e->s;
        $this->c = $e->c;
        $this->min = $e->min;
        $this->max = $e->max;
        $this->digits = $e->digits;
        $this->from = $e->from;
        $this->to = $e->to;
    }

//    public function toAutomaton(): Automaton {
//        return $this->toAutomaton(null, null, 10000);
//    }

    public function toAutomaton(Automata $automata = null, AutomatonProvider $automaton_provider = null, int $maxDeterminizedStates = 10000): Automaton
    {
        try {
            return $this->toAutomatonInternal($automata, $automaton_provider, $maxDeterminizedStates);
        } catch (TooComplexToDeterminizeException $e) {
            throw new TooComplexToDeterminizeException($this, 0, $e);
        }
    }

    private function toAutomatonInternal($automata, $automaton_provider, int $maxDeterminizedStates): Automaton
    {
        static $level = -4;
        $a = null;
        $level += 4;
        var_dump(str_repeat(' ', $level) . 'kind: ' . $this->kind);
        switch ($this->kind) {
            case Kind::REGEXP_UNION:
                $list = [];
                $this->findLeaves($this->exp1, Kind::REGEXP_UNION, $list, $automata, $automaton_provider, $maxDeterminizedStates);
                $this->findLeaves($this->exp2, Kind::REGEXP_UNION, $list, $automata, $automaton_provider, $maxDeterminizedStates);
                $a = Operations::unionAsArray($list);
                $a = MinimizationOperations::minimize($a, $maxDeterminizedStates);
                break;
            case Kind::REGEXP_CONCATENATION:
                $list = [];
                $this->findLeaves($this->exp1, Kind::REGEXP_CONCATENATION, $list, $automata, $automaton_provider, $maxDeterminizedStates);
                $this->findLeaves($this->exp2, Kind::REGEXP_CONCATENATION, $list, $automata, $automaton_provider, $maxDeterminizedStates);
                $a = Operations::concatenateAsArray($list);
                $a = MinimizationOperations::minimize($a, $maxDeterminizedStates);
                break;
            case Kind::REGEXP_INTERSECTION:
                $a = Operations::intersection($this->exp1->toAutomatonInternal($automata, $automaton_provider, $maxDeterminizedStates), $this->exp2->toAutomatonInternal($automata, $automaton_provider, $maxDeterminizedStates));
                $a = MinimizationOperations::minimize($a, $maxDeterminizedStates);
                break;
            case Kind::REGEXP_OPTIONAL:
                $a = Operations::optional($this->exp1->toAutomatonInternal($automata, $automaton_provider, $maxDeterminizedStates));
                $a = MinimizationOperations::minimize($a, $maxDeterminizedStates);
                break;
            case Kind::REGEXP_REPEAT:
                $a = Operations::repeat($this->exp1->toAutomatonInternal($automata, $automaton_provider, $maxDeterminizedStates));
                $a = MinimizationOperations::minimize($a, $maxDeterminizedStates);
                break;
            case Kind::REGEXP_REPEAT_MIN:
                $a = Operations::repeatCount($this->exp1->toAutomatonInternal($automata, $automaton_provider, $maxDeterminizedStates), $this->min);
                $a = MinimizationOperations::minimize($a, $maxDeterminizedStates);
                break;
            case Kind::REGEXP_REPEAT_MINMAX:
                $a = Operations::repeatMinMax($this->exp1->toAutomatonInternal($automata, $automaton_provider, $maxDeterminizedStates), $this->min, $this->max);
                $a = MinimizationOperations::minimize($a, $maxDeterminizedStates);
                break;
            case Kind::REGEXP_COMPLEMENT:
                $a = Operations::complement($this->exp1->toAutomatonInternal($automata, $automaton_provider, $maxDeterminizedStates), $maxDeterminizedStates);
                $a = MinimizationOperations::minimize($a, $maxDeterminizedStates);
                break;
            case Kind::REGEXP_CHAR:
                $a = Automata::makeChar($this->c);
                break;
            case Kind::REGEXP_CHAR_RANGE:
                $a = Automata::makeCharRange($this->from, $this->to);
                break;
            case Kind::REGEXP_ANYCHAR:
                $a = Automata::makeAnyChar();
                break;
            case Kind::REGEXP_EMPTY:
                $a = Automata::makeEmpty();
                break;
            case Kind::REGEXP_STRING:
                $a = Automata::makeString($this->s);
                break;
            case Kind::REGEXP_ANYSTRING:
                $a = Automata::makeAnyString();
                break;
            case Kind::REGEXP_AUTOMATON:
                /** @var Automaton $aa */
                $aa = null;
                if ($automata != null) {
                    $aa = Automata::get($this->s);
                }

                if ($aa == null && $automaton_provider != null) {
                    try {
                        $aa = $automaton_provider->getAutomaton($this->s);
                    } catch (IOException $e) {
                        throw new IllegalArgumentException($e->getMessage(), $e->getCode(), $e);
                    }
                }

                if ($aa == null) {
                    throw new IllegalArgumentException("'{$this->s}' not found");
                }

                $a = $aa;
                break;
            case Kind::REGEXP_INTERVAL:
                $a = Automata::makeDecimalInterval($this->min, $this->max, $this->digits);
        }
        var_dump(str_repeat(' ', $level) . 'nums: ' . $a->getNumStates());
        $level -= 4;

        return $a;
    }

    private function findLeaves(RegExp $exp, $kind, &$list, $automata, $automaton_provider, int $maxDeterminizedStates)
    {
        if ($exp->kind == $kind) {
            $this->findLeaves($exp->exp1, $kind, $list, $automata, $automaton_provider, $maxDeterminizedStates);
            $this->findLeaves($exp->exp2, $kind, $list, $automata, $automaton_provider, $maxDeterminizedStates);
        } else {
            $list[] = $exp->toAutomatonInternal($automata, $automaton_provider, $maxDeterminizedStates);
        }
    }

    public function getOriginalString(): string
    {
        return $this->originalString;
    }

    public function __toString()
    {
        $b = '';
        $this->toStringBuilder($b);
        return $b;
    }

    function toStringBuilder(&$b)
    {
        switch ($this->kind) {
            case Kind::REGEXP_UNION:
                $b .= "(";
                $this->exp1->toStringBuilder($b);
                $b .= "|";
                $this->exp2->toStringBuilder($b);
                $b .= ")";
                break;
            case Kind::REGEXP_CONCATENATION:
                $this->exp1->toStringBuilder($b);
                $this->exp2->toStringBuilder($b);
                break;
            case Kind::REGEXP_INTERSECTION:
                $b .= "(";
                $this->exp1->toStringBuilder($b);
                $b .= "&";
                $this->exp2->toStringBuilder($b);
                $b .= ")";
                break;
            case Kind::REGEXP_OPTIONAL:
                $b .= "(";
                $this->exp1->toStringBuilder($b);
                $b .= ")?";
                break;
            case Kind::REGEXP_REPEAT:
                $b .= "(";
                $this->exp1->toStringBuilder($b);
                $b .= ")*";
                break;
            case Kind::REGEXP_REPEAT_MIN:
                $b .= "(";
                $this->exp1->toStringBuilder($b);
                $b .= "){" . $this->min . ",}";
                break;
            case Kind::REGEXP_REPEAT_MINMAX:
                $b .= "(";
                $this->exp1->toStringBuilder($b);
                $b .= "){" . $this->min . "," . $this->max . "}";
                break;
            case Kind::REGEXP_COMPLEMENT:
                $b .= "~(";
                $this->exp1->toStringBuilder($b);
                $b .= ")";
                break;
            case Kind::REGEXP_CHAR:
                $b .= "\\" . \IntlChar::ord($this->c);
                break;
            case Kind::REGEXP_CHAR_RANGE:
                $b .= "[\\" . \IntlChar::ord($this->from) . "-\\" . \IntlChar::ord($this->to) . "]";
                break;
            case Kind::REGEXP_ANYCHAR:
                $b .= ".";
                break;
            case Kind::REGEXP_EMPTY:
                $b .= "#";
                break;
            case Kind::REGEXP_STRING:
                $b .= "\"" . $this->s .= "\"";
                break;
            case Kind::REGEXP_ANYSTRING:
                $b .= "@";
                break;
            case Kind::REGEXP_AUTOMATON:
                $b .= "<" . $this->s . ">";
                break;
            case Kind::REGEXP_INTERVAL:
                $s1 = (string)$this->min;
                $s2 = (string)$this->max;
                $b .= "<";
                if ($this->digits > 0) {
                    for ($i = mb_strlen($s1); $i < $this->digits; ++$i) {
                        $b .= '0';
                    }
                }

                $b .= $s1 . "-";
                if ($this->digits > 0) {
                    for ($i = mb_strlen($s2); $i < $this->digits; ++$i) {
                        $b .= '0';
                    }
                }

                $b .= $s2 . ">";
        }

    }

    public function toStringTree(&$b = '', $indent = ''): string
    {
        switch ($this->kind) {
            case Kind::REGEXP_UNION:
            case Kind::REGEXP_CONCATENATION:
            case Kind::REGEXP_INTERSECTION:
                $b .= $indent;
                $b .= $this->kind;
                $b .= "\n";
                $this->exp1->toStringTree($b, $indent . "  ");
                $this->exp2->toStringTree($b, $indent . "  ");
                break;
            case Kind::REGEXP_OPTIONAL:
            case Kind::REGEXP_REPEAT:
            case Kind::REGEXP_COMPLEMENT:
                $b .= $indent;
                $b .= $this->kind;
                $b .= "\n";
                $this->exp1->toStringTree($b, $indent . "  ");
                break;
            case Kind::REGEXP_REPEAT_MIN:
                $b .= $indent;
                $b .= $this->kind;
                $b .= " min=";
                $b .= $this->min;
                $b .= '\n';
                $this->exp1->toStringTree($b, $indent . "  ");
                break;
            case Kind::REGEXP_REPEAT_MINMAX:
                $b .= $indent;
                $b .= $this->kind;
                $b .= " min=";
                $b .= $this->min;
                $b .= " max=";
                $b .= $this->max;
                $b .= '\n';
                $this->exp1->toStringTree($b, $indent . "  ");
                break;
            case Kind::REGEXP_CHAR:
                $b .= $indent;
                $b .= $this->kind;
                $b .= " char=";
                $b .= \IntlChar::ord($this->c);
                $b .= '\n';
                break;
            case Kind::REGEXP_CHAR_RANGE:
                $b .= $indent;
                $b .= $this->kind;
                $b .= " from=";
                $b .= \IntlChar::ord($this->from);
                $b .= " to=";
                $b .= \IntlChar::ord($this->to);
                $b .= '\n';
                break;
            case Kind::REGEXP_ANYCHAR:
            case Kind::REGEXP_EMPTY:
                $b .= $indent;
                $b .= $this->kind;
                $b .= '\n';
                break;
            case Kind::REGEXP_STRING:
                $b .= $indent;
                $b .= $this->kind;
                $b .= " string=";
                $b .= $this->s;
                $b .= '\n';
                break;
            case Kind::REGEXP_ANYSTRING:
                $b .= $indent;
                $b .= $this->kind;
                $b .= '\n';
                break;
            case Kind::REGEXP_AUTOMATON:
                $b .= $indent;
                $b .= $this->kind;
                $b .= '\n';
                break;
            case Kind::REGEXP_INTERVAL:
                $b .= $indent;
                $b .= $this->kind;
                $s1 = (string)$this->min;
                $s2 = (string)$this->max;
                $b .= "<";
                if ($this->digits > 0) {
                    for ($i = mb_strlen($s1); $i < $this->digits; ++$i) {
                        $b .= '0';
                    }
                }

                $b .= $s1 . "-";
                if ($this->digits > 0) {
                    for ($i = mb_strlen($s2); $i < $this->digits; ++$i) {
                        $b .= '0';
                    }
                }

                $b .= $s2 . ">";
                $b .= '\n';
        }

        return $b;
    }

    public function getIdentifiers(array &$set = []): array
    {
        switch ($this->kind) {
            case Kind::REGEXP_UNION:
            case Kind::REGEXP_CONCATENATION:
            case Kind::REGEXP_INTERSECTION:
                $this->exp1->getIdentifiers($set);
                $this->exp2->getIdentifiers($set);
                break;
            case Kind::REGEXP_OPTIONAL:
            case Kind::REGEXP_REPEAT:
            case Kind::REGEXP_REPEAT_MIN:
            case Kind::REGEXP_REPEAT_MINMAX:
            case Kind::REGEXP_COMPLEMENT:
                $this->exp1->getIdentifiers($set);
            case Kind::REGEXP_CHAR:
            case Kind::REGEXP_CHAR_RANGE:
            case Kind::REGEXP_ANYCHAR:
            case Kind::REGEXP_EMPTY:
            case Kind::REGEXP_STRING:
            case Kind::REGEXP_ANYSTRING:
            default:
                break;
            case Kind::REGEXP_AUTOMATON:
                $set[] = $this->s;
        }

        return $set;
    }

    static public function makeUnion(RegExp $exp1, RegExp $exp2): RegExp
    {
        $r = new RegExp();
        $r->kind = Kind::REGEXP_UNION;
        $r->exp1 = $exp1;
        $r->exp2 = $exp2;
        return $r;
    }

    static public function makeConcatenation(RegExp $exp1, RegExp $exp2): RegExp
    {
        if ($exp1->kind != Kind::REGEXP_CHAR && $exp1->kind != Kind::REGEXP_STRING || $exp2->kind != Kind::REGEXP_CHAR && $exp2->kind != Kind::REGEXP_STRING) {
            $r = new RegExp();
            $r->kind = Kind::REGEXP_CONCATENATION;
            if ($exp1->kind == Kind::REGEXP_CONCATENATION && ($exp1->$exp2->kind == Kind::REGEXP_CHAR || $exp1->$exp2->kind == Kind::REGEXP_STRING) && ($exp2->kind == Kind::REGEXP_CHAR || $exp2->kind == Kind::REGEXP_STRING)) {
                $r->exp1 = $exp1->exp1;
                $r->exp2 = self::makeString($exp1->exp2, $exp2);
            } else {
                if ($exp1->kind != Kind::REGEXP_CHAR && $exp1->kind != Kind::REGEXP_STRING || $exp2->kind != Kind::REGEXP_CONCATENATION || $exp2->$exp1->kind != Kind::REGEXP_CHAR && $exp2->$exp1->kind != Kind::REGEXP_STRING) {
                    $r->exp1 = $exp1;
                    $r->exp2 = $exp2;
                } else {
                    $r->exp1 = self::makeString($exp1, $exp2->exp1);
                    $r->exp2 = $exp2->exp2;
                }
            }

            return $r;
        } else {
            return self::makeString($exp1, $exp2);
        }
    }

    private static function makeString($exp1, RegExp $exp2 = null): RegExp
    {
        if (is_string($exp1)) {
            $r = new RegExp();
            $r->kind = Kind::REGEXP_STRING;
            $r->s = $exp1;

            return $r;
        }

        $b = '';
        if ($exp1->kind == Kind::REGEXP_STRING) {
            $b .= $exp1->s;
        } else {
            $b .= \IntlChar::ord($exp1->c);
        }

        if ($exp2->kind == Kind::REGEXP_STRING) {
            $b .= $exp2->s;
        } else {
            $b .= \IntlChar::ord($exp2->c);
        }

        return self::makeString($b);
    }

    static public function makeIntersection(RegExp $exp1, RegExp $exp2): RegExp
    {
        $r = new RegExp();
        $r->kind = Kind::REGEXP_INTERSECTION;
        $r->exp1 = $exp1;
        $r->exp2 = $exp2;
        return $r;
    }

    static public function makeOptional(RegExp $exp): RegExp
    {
        $r = new RegExp();
        $r->kind = Kind::REGEXP_OPTIONAL;
        $r->exp1 = $exp;
        return $r;
    }

    static public function makeRepeat(RegExp $exp, int $min = null, int $max = null): RegExp
    {
        $r = new RegExp();

        if ($min === null && $max !== null) {
            throw new IllegalArgumentException("Min must be not null, when max not null");
        }

        $r->exp1 = $exp;
        $r->kind = Kind::REGEXP_REPEAT;
        if ($min !== null) {
            $r->kind = Kind::REGEXP_REPEAT_MIN;
            $r->min = $min;
        }
        if ($max !== null) {
            $r->kind = Kind::REGEXP_REPEAT_MINMAX;
            $r->max = $max;
        }

        return $r;
    }

    static public function makeComplement(RegExp $exp): RegExp
    {
        $r = new RegExp();
        $r->kind = Kind::REGEXP_COMPLEMENT;
        $r->exp1 = $exp;
        return $r;
    }

    static public function makeChar(int $c): RegExp
    {
        $r = new RegExp();
        $r->kind = Kind::REGEXP_CHAR;
        $r->c = $c;
        return $r;
    }

    static public function makeCharRange(int $from, int $to): RegExp
    {
        if ($from > $to) {
            throw new IllegalArgumentException("invalid range: from (" . $from . ") cannot be > to (" . $to . ")");
        } else {
            $r = new RegExp();
            $r->kind = Kind::REGEXP_CHAR_RANGE;
            $r->from = $from;
            $r->to = $to;
            return $r;
        }
    }

    static public function makeAnyChar(): RegExp
    {
        $r = new RegExp();
        $r->kind = Kind::REGEXP_ANYCHAR;
        return $r;
    }

    static public function makeEmpty(): RegExp
    {
        $r = new RegExp();
        $r->kind = Kind::REGEXP_EMPTY;
        return $r;
    }

    static public function makeAnyString(): RegExp
    {
        $r = new RegExp();
        $r->kind = Kind::REGEXP_ANYSTRING;
        return $r;
    }

    static public function makeAutomaton($s): RegExp
    {
        $r = new RegExp();
        $r->kind = Kind::REGEXP_AUTOMATON;
        $r->s = $s;
        return $r;
    }

    static public function makeInterval(int $min, int $max, int $digits): RegExp
    {
        $r = new RegExp();
        $r->kind = Kind::REGEXP_INTERVAL;
        $r->min = $min;
        $r->max = $max;
        $r->digits = $digits;
        return $r;
    }

    private function peek(string  $s): bool
    {
        return $this->more() && mb_strpos($s, mb_substr($this->originalString, $this->pos, 1)) !== false;
    }

    private function match(int $c): bool
    {
        if ($this->pos >= mb_strlen($this->originalString)) {
            return false;
        } elseif (\IntlChar::ord(mb_substr($this->originalString, $this->pos, 1)) == $c) {
            $this->pos += mb_strlen(\IntlChar::chr($c));
            return true;
        } else {
            return false;
        }
    }

    private function more(): bool
    {
        return $this->pos < mb_strlen($this->originalString);
    }

    private function next(): int
    {
        if (!$this->more()) {
            throw new IllegalArgumentException("unexpected end-of-string");
        } else {
            $ch = \IntlChar::ord(mb_substr($this->originalString, $this->pos, 1));
            $this->pos += mb_strlen(\IntlChar::chr($ch));
            return $ch;
        }
    }

    private function check(int $flag): bool
    {
        return ($this->flags & $flag) != 0;
    }

    final private function parseUnionExp(): RegExp
    {
        $e = $this->parseInterExp();
        if ($this->match(124)) {
            $e = self::makeUnion($e, $this->parseUnionExp());
        }

        return $e;
    }

    final private function parseInterExp(): RegExp
    {
        $e = $this->parseConcatExp();
        if ($this->check(1) && $this->match(38)) {
            $e = self::makeIntersection($e, $this->parseInterExp());
        }

        return $e;
    }

    final public function parseConcatExp(): RegExp
    {
        $e = $this->parseRepeatExp();
        if ($this->more() && !$this->peek(")|") && (!$this->check(1) || !$this->peek("&"))) {
            $e = self::makeConcatenation($e, $this->parseConcatExp());
        }

        return $e;
    }

    final public function parseRepeatExp(): RegExp
    {
        $e = $this->parseComplExp();

        while (true) {
            while ($this->peek("?*+{")) {
                if ($this->match(63)) {
                    $e = self::makeOptional($e);
                } else {
                    if ($this->match(42)) {
                        $e = self::makeRepeat($e);
                    } else {
                        if ($this->match(43)) {
                            $e = self::makeRepeat($e, 1);
                        } else {
                            if ($this->match(123)) {
                                $start = $this->pos;

                                while ($this->peek("0123456789")) {
                                    $this->next();
                                }

                                if ($start == $this->pos) {
                                    throw new IllegalArgumentException("integer expected at position " . $this->pos);
                                }

                                $n = (int)(mb_substr($this->originalString, $start, $this->pos));
                                $m = -1;
                                if ($this->match(44)) {
                                    $start = $this->pos;

                                    while ($this->peek("0123456789")) {
                                        $this->next();
                                    }

                                    if ($start != $this->pos) {
                                        $m = (int)(mb_substr($this->originalString, $start, $this->pos));
                                    }
                                } else {
                                    $m = $n;
                                }

                                if (!$this->match(125)) {
                                    throw new IllegalArgumentException("expected '}' at position " . $this->pos);
                                }

                                if ($m == -1) {
                                    $e = self::makeRepeat($e, $n);
                                } else {
                                    $e = self::makeRepeat($e, $n, $m);
                                }
                            }
                        }
                    }
                }
            }

            return $e;
        }
    }

    final public function parseComplExp(): RegExp
    {
        return $this->check(2) && $this->match(126) ? self::makeComplement($this->parseComplExp()) : $this->parseCharClassExp();
    }

    final public function parseCharClassExp(): RegExp
    {
        if ($this->match(91)) {
            $negate = false;
            if ($this->match(94)) {
                $negate = true;
            }

            $e = $this->parseCharClasses();
            if ($negate) {
                $e = self::makeIntersection(self::makeAnyChar(), self::makeComplement($e));
            }


            if (!$this->match(93)) {
                throw new IllegalArgumentException("expected ']' at position " . $this->pos);
            } else {
                return $e;
            }
        } else {
            return $this->parseSimpleExp();
        }
    }

    final public function parseCharClassesP(): RegExp
    {
        for ($e = $this->parseCharClass(); $this->more() && !$this->peek("]"); $e = self::makeUnion($e, $this->parseCharClass())) {
        }

        return $e;
    }

    final public function parseCharClasses(): RegExp
    {
        $e = self::parseCharClass();
        while ($this->more() && !$this->peek("]"))
            $e = self::makeUnion($e, self::parseCharClass());
        return $e;
    }

    final public function parseCharClass(): RegExp
    {
        $c = $this->parseCharExp();
        return $this->match(45) ? self::makeCharRange($c, $this->parseCharExp()) : self::makeChar($c);
    }

    final public function parseSimpleExp(): RegExp
    {
        if ($this->match(46)) {
            return self::makeAnyChar();
        } else {
            if ($this->check(4) && $this->match(35)) {
                return self::makeEmpty();
            } else {
                if ($this->check(8) && $this->match(64)) {
                    return self::makeAnyString();
                } else {
                    if ($this->match(34)) {
                        $start = $this->pos;

                        while ($this->more() && !$this->peek("\"")) {
                            $this->next();
                        }

                        if (!$this->match(34)) {
                            throw new IllegalArgumentException("expected '\"' at position " . $this->pos);
                        } else {
                            return self::makeString(mb_substr($this->originalString, $start, $this->pos - 1));
                        }
                    } else {
                        if ($this->match(40)) {
                            if ($this->match(41)) {
                                return self::makeString("");
                            } else {
                                $e = $this->parseUnionExp();
                                if (!$this->match(41)) {
                                    throw new IllegalArgumentException("expected ')' at position " . $this->pos);
                                } else {
                                    return $e;
                                }
                            }
                        } else {
                            if (($this->check(16) || $this->check(32)) && $this->match(60)) {
                                $start = $this->pos;

                                while ($this->more() && !$this->peek(">")) {
                                    $this->next();
                                }

                                if (!$this->match(62)) {
                                    throw new IllegalArgumentException("expected '>' at position " . $this->pos);
                                } else {
                                    $s = mb_substr($this->originalString, $start, $this->pos - 1);
                                    $i = mb_strpos($s, chr(45));
                                    if ($i == -1) {
                                        if (!$this->check(16)) {
                                            throw new IllegalArgumentException("interval syntax error at position " . ($this->pos - 1));
                                        } else {
                                            return self::makeAutomaton($s);
                                        }
                                    } elseif (!$this->check(32)) {
                                        throw new IllegalArgumentException("illegal identifier at position " . ($this->pos - 1));
                                    } else {
                                        try {
                                            if ($i != 0 && $i != mb_strlen($s) - 1 && $i == mb_strrpos($s, chr(45))) {
                                                $smin = mb_substr($s, 0, $i);
                                                $smax = mb_substr($s, $i + 1, mb_strlen($s));
                                                $imin = (int)($smin);
                                                $imax = (int)($smax);
                                                if (mb_strlen($smin) == mb_strlen($smax)) {
                                                    $digits = mb_strlen($smin);
                                                } else {
                                                    $digits = 0;
                                                }

                                                if ($imin > $imax) {
                                                    $t = $imin;
                                                    $imin = $imax;
                                                    $imax = $t;
                                                }

                                                return self::makeInterval($imin, $imax, $digits);
                                            } else {
                                                throw new NumberFormatException();
                                            }
                                        } catch (NumberFormatException $e) {
                                            throw new IllegalArgumentException("interval syntax error at position " . ($this->pos - 1));
                                        }
                                    }
                                }
                            } else {
                                return self::makeChar($this->parseCharExp());
                            }
                        }
                    }
                }
            }
        }
    }

    final public function parseCharExp(): int
    {
        $this->match(92);
        return $this->next();
    }
}

class Kind
{
    const REGEXP_UNION         = 0;
    const REGEXP_CONCATENATION = 1;
    const REGEXP_INTERSECTION  = 2;
    const REGEXP_OPTIONAL      = 3;
    const REGEXP_REPEAT        = 4;
    const REGEXP_REPEAT_MIN    = 5;
    const REGEXP_REPEAT_MINMAX = 6;
    const REGEXP_COMPLEMENT    = 7;
    const REGEXP_CHAR          = 8;
    const REGEXP_CHAR_RANGE    = 9;
    const REGEXP_ANYCHAR       = 10;
    const REGEXP_EMPTY         = 11;
    const REGEXP_STRING        = 12;
    const REGEXP_ANYSTRING     = 13;
    const REGEXP_AUTOMATON     = 14;
    const REGEXP_INTERVAL      = 15;
}

class TooComplexToDeterminizeException extends \Exception
{
}

class NumberFormatException extends \Exception
{
}