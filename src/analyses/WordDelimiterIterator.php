<?php

namespace ftIndex\analyses;

use IntlChar;

/**
 * A BreakIterator-like API for iterating over subwords in text, according to WordDelimiterGraphFilter rules.
 * @lucene.internal
 */
final class WordDelimiterIterator
{

    const LOWER         = 0x01;
    const UPPER         = 0x02;
    const DIGIT         = 0x04;
    const SUBWORD_DELIM = 0x08;

    // combinations: for testing, not for setting bits
    public const ALPHA    = 0x03;
    public const ALPHANUM = 0x07;

    /** Indicates the end of iteration */
    public const DONE = -1;

    public static $DEFAULT_WORD_DELIM_TABLE;

    public $text = "";
    public $length;

    /** start position of text, excluding leading delimiters */
    public $startBounds;
    /** end position of text, excluding trailing delimiters */
    public $endBounds;

    /** Beginning of subword */
    public $current;
    /** End of subword */
    public $end;

    /* does this string end with a possessive such as 's */
    private $hasFinalPossessive = false;

    /**
     * If false, causes case changes to be ignored (subwords will only be generated
     * given SUBWORD_DELIM tokens). (Defaults to true)
     * @var bool
     */
    public $splitOnCaseChange;

    /**
     * If false, causes numeric changes to be ignored (subwords will only be generated
     * given SUBWORD_DELIM tokens). (Defaults to true)
     * @var bool
     */
    public $splitOnNumerics;

    /**
     * If true, causes trailing "'s" to be removed for each subword. (Defaults to true)
     * <p/>
     * "O'Neil's" =&gt; "O", "Neil"
     * @var bool
     */
    public $stemEnglishPossessive;

    private $charTypeTable = [];

    /** if true, need to skip over a possessive found in the last call to next() */
    private $skipPossessive = false;


    /**
     * Create a new WordDelimiterIterator operating with the supplied rules.
     *
     * @param array $charTypeTable table containing character types
     * @param bool $splitOnCaseChange if true, causes "PowerShot" to be two tokens; ("Power-Shot" remains two parts regardless)
     * @param bool $splitOnNumerics if true, causes "j2se" to be three tokens; "j" "2" "se"
     * @param bool $stemEnglishPossessive if true, causes trailing "'s" to be removed for each subword: "O'Neil's" =&gt; "O", "Neil"
     */
    public function __construct(array $charTypeTable = null, bool $splitOnCaseChange = true, bool $splitOnNumerics = true, bool $stemEnglishPossessive = true)
    {
        if ($charTypeTable === null) {
            $charTypeTable = self::getCharTypeTable();
        }
        $this->charTypeTable = $charTypeTable;
        $this->splitOnCaseChange = $splitOnCaseChange;
        $this->splitOnNumerics = $splitOnNumerics;
        $this->stemEnglishPossessive = $stemEnglishPossessive;
    }

    public static function getCharTypeTable()
    {
        // TODO: should there be a WORD_DELIM category for chars that only separate words (no catenation of subwords will be
        // done if separated by these chars?) "," would be an obvious candidate...
        if (WordDelimiterIterator::$DEFAULT_WORD_DELIM_TABLE === null) {
            $tab = [];
            for ($i = 0; $i < 256; $i++) {
                $code = 0;
                if (IntlChar::isULowercase($i)) {
                    $code |= WordDelimiterIterator::LOWER;
                } else {
                    if (IntlChar::isUUppercase($i)) {
                        $code |= WordDelimiterIterator::UPPER;
                    } else {
                        if (IntlChar::isdigit($i)) {
                            $code |= WordDelimiterIterator::DIGIT;
                        }
                    }
                }
                if ($code == 0) {
                    $code = WordDelimiterIterator::SUBWORD_DELIM;
                }
                $tab[$i] = $code;
            }
            WordDelimiterIterator::$DEFAULT_WORD_DELIM_TABLE = $tab;
        }

        return WordDelimiterIterator::$DEFAULT_WORD_DELIM_TABLE;
    }

    /**
     * Advance to the next subword in the string.
     *
     * @return int index of the next subword, or {@link #DONE} if all subwords have been returned
     */
    public function next(): int
    {
        $this->current = $this->end;
        if ($this->current == self::DONE) {
            return self::DONE;
        }

        if ($this->skipPossessive) {
            $this->current += 2;
            $this->skipPossessive = false;
        }

        $lastType = 0;

        while ($this->current < $this->endBounds && ($this->isSubwordDelim($lastType = $this->charType(\IntlChar::ord(mb_substr($this->text, $this->current, 1)))))) {
            $this->current++;
        }

        if ($this->current >= $this->endBounds) {
            return $this->end = self::DONE;
        }

        for ($this->end = $this->current + 1; $this->end < $this->endBounds; $this->end++) {
            $type = $this->charType(\IntlChar::ord(mb_substr($this->text, $this->end, 1)));
            if ($this->isBreak($lastType, $type)) {
                break;
            }
            $lastType = $type;
        }

        if ($this->end < $this->endBounds - 1 && $this->endsWithPossessive($this->end + 2)) {
            $this->skipPossessive = true;
        }

        return $this->end;
    }


    /**
     * Return the type of the current subword.
     * This currently uses the type of the first character in the subword.
     *
     * @return int type of the current word
     */
    public function type(): int
    {
        if ($this->end == self::DONE) {
            return 0;
        }

        $type = $this->charType(\IntlChar::ord(mb_substr($this->text, $this->current, 1)));
        switch ($type) {
            // return ALPHA word type for both lower and upper
            case self::LOWER:
            case self::UPPER:
                return self::ALPHA;
            default:
                return $type;
        }
    }

    /**
     * Reset the text to a new value, and reset all state
     *
     * @param string $text   New text
     * @param int    $length length of the text
     */
    public function setText($text, int $length)
    {
        $this->text = $text;
        $this->length = $this->endBounds = $length;
        $this->current = $this->startBounds = $this->end = 0;
        $this->skipPossessive = $this->hasFinalPossessive = false;
        $this->setBounds();
    }

    // ================================================= Helper Methods ================================================

    /**
     * Determines whether the transition from lastType to type indicates a break
     *
     * @param int $lastType Last subword type
     * @param int $type Current subword type
     *
     * @return {@code true} if the transition indicates a break, {@code false} otherwise
     */
    private function isBreak(int $lastType, int $type): bool
    {
        if (($type & $lastType) != 0) {
            return false;
        }

        if (!$this->splitOnCaseChange && $this->isAlpha($lastType) && $this->isAlpha($type)) {
            // ALPHA->ALPHA: always ignore if case isn't considered.
            return false;
        } else {
            if ($this->isUpper($lastType) && $this->isAlpha($type)) {
                // UPPER->letter: Don't split
                return false;
            } else {
                if (!$this->splitOnNumerics && (($this->isAlpha($lastType) && $this->isDigit($type)) || ($this->isDigit($lastType) && $this->isAlpha($type)))) {
                    // ALPHA->NUMERIC, NUMERIC->ALPHA :Don't split
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Determines if the current word contains only one subword.  Note, it could be potentially surrounded by delimiters
     *
     * @return {@code true} if the current word contains only one subword, {@code false} otherwise
     */
    public function isSingleWord(): bool
    {
        if ($this->hasFinalPossessive) {
            return $this->current == $this->startBounds && $this->end == $this->endBounds - 2;
        } else {
            return $this->current == $this->startBounds && $this->end == $this->endBounds;
        }
    }

    /**
     * Set the internal word bounds (remove leading and trailing delimiters). Note, if a possessive is found, don't remove
     * it yet, simply note it.
     */
    private function setBounds()
    {
        while ($this->startBounds < $this->length && ($this->isSubwordDelim($this->charType(\IntlChar::ord(mb_substr($this->text, $this->startBounds, 1)))))) {
            $this->startBounds++;
        }

        while ($this->endBounds > $this->startBounds && ($this->isSubwordDelim($this->charType(\IntlChar::ord(mb_substr($this->text, $this->endBounds - 1, 1)))))) {
            $this->endBounds--;
        }
        if ($this->endsWithPossessive($this->endBounds)) {
            $this->hasFinalPossessive = true;
        }
        $this->current = $this->startBounds;
    }

    /**
     * Determines if the text at the given position indicates an English possessive which should be removed
     *
     * @param int $pos Position in the text to check if it indicates an English possessive
     *
     * @return {@code true} if the text at the position indicates an English posessive, {@code false} otherwise
     */
    private function endsWithPossessive(int $pos): bool
    {
        return ($this->stemEnglishPossessive &&
            $pos > 2 &&
            mb_substr($this->text, $pos - 2, 1) == '\'' &&
            (mb_substr($this->text, $pos - 1, 1) == 's' || mb_substr($this->text, $pos - 1, 1) == 'S') &&
            $this->isAlpha($this->charType(\IntlChar::ord(mb_substr($this->text, $pos - 3, 1))) &&
                ($pos == $this->endBounds || $this->isSubwordDelim($this->charType(\IntlChar::ord(mb_substr($this->text, $pos, 1)))))));
    }

    /**
     * Determines the type of the given character
     *
     * @param int $ch Character whose type is to be determined
     *
     * @return int Type of the character
     */
    private function charType(int $ch): int
    {
        if ($ch < count($this->charTypeTable)) {
            return $this->charTypeTable[$ch];
        }
        return $this->getType($ch);
    }

    /**
     * Computes the type of the given character
     *
     * @param int $ch Character whose type is to be determined
     *
     * @return int Type of the character
     */
    public static function getType(int $ch): int
    {
        switch (IntlChar::charType($ch)) {
            case IntlChar::CHAR_CATEGORY_UPPERCASE_LETTER:
                return self::UPPER;
            case IntlChar::CHAR_CATEGORY_LOWERCASE_LETTER:
                return self::LOWER;

            case IntlChar::CHAR_CATEGORY_TITLECASE_LETTER:
            case IntlChar::CHAR_CATEGORY_MODIFIER_LETTER:
            case IntlChar::CHAR_CATEGORY_OTHER_LETTER:
            case IntlChar::CHAR_CATEGORY_NON_SPACING_MARK:
            case IntlChar::CHAR_CATEGORY_ENCLOSING_MARK:  // depends what it encloses?
            case IntlChar::CHAR_CATEGORY_COMBINING_SPACING_MARK:
                return self::ALPHA;

            case IntlChar::CHAR_CATEGORY_DECIMAL_DIGIT_NUMBER:
            case IntlChar::CHAR_CATEGORY_LETTER_NUMBER:
            case IntlChar::CHAR_CATEGORY_OTHER_NUMBER:
                return self::DIGIT;

            // case \IntlChar::CHAR_CATEGORY_SPACE_SEPARATOR:
            // case \IntlChar::CHAR_CATEGORY_LINE_SEPARATOR:
            // case \IntlChar::CHAR_CATEGORY_PARAGRAPH_SEPARATOR:
            // case \IntlChar::CHAR_CATEGORY_CONTROL:
            // case \IntlChar::CHAR_CATEGORY_FORMAT:
            // case \IntlChar::CHAR_CATEGORY_PRIVATE_USE:

            case IntlChar::CHAR_CATEGORY_SURROGATE:  // prevent splitting
                return self::ALPHA | self::DIGIT;

            // case \IntlChar::CHAR_CATEGORY_DASH_PUNCTUATION:
            // case \IntlChar::CHAR_CATEGORY_START_PUNCTUATION:
            // case \IntlChar::CHAR_CATEGORY_END_PUNCTUATION:
            // case \IntlChar::CHAR_CATEGORY_CONNECTOR_PUNCTUATION:
            // case \IntlChar::CHAR_CATEGORY_OTHER_PUNCTUATION:
            // case \IntlChar::CHAR_CATEGORY_MATH_SYMBOL:
            // case \IntlChar::CHAR_CATEGORY_CURRENCY_SYMBOL:
            // case \IntlChar::CHAR_CATEGORY_MODIFIER_SYMBOL:
            // case \IntlChar::CHAR_CATEGORY_OTHER_SYMBOL:
            // case \IntlChar::CHAR_CATEGORY_INITIAL_QUOTE_PUNCTUATION:
            // case \IntlChar::CHAR_CATEGORY_FINAL_QUOTE_PUNCTUATION:

            default:
                return self::SUBWORD_DELIM;
        }
    }

    /**
     * Checks if the given word type includes {@link #ALPHA}
     *
     * @param int $type Word type to check
     *
     * @return {@code true} if the type contains ALPHA, {@code false} otherwise
     */
    static public function isAlpha(int $type): bool
    {
        return ($type & self::ALPHA) != 0;
    }

    /**
     * Checks if the given word type includes {@link #DIGIT}
     *
     * @param int $type Word type to check
     *
     * @return {@code true} if the type contains DIGIT, {@code false} otherwise
     */
    static public function isDigit(int $type): bool
    {
        return ($type & self::DIGIT) != 0;
    }

    /**
     * Checks if the given word type includes {@link #SUBWORD_DELIM}
     *
     * @param int $type Word type to check
     *
     * @return {@code true} if the type contains SUBWORD_DELIM, {@code false} otherwise
     */
    static public function isSubwordDelim(int $type): bool
    {
        return ($type & self::SUBWORD_DELIM) != 0;
    }

    /**
     * Checks if the given word type includes {@link #UPPER}
     *
     * @param int $type Word type to check
     *
     * @return {@code true} if the type contains UPPER, {@code false} otherwise
     */
    static public function isUpper(int $type): bool
    {
        return ($type & self::UPPER) != 0;
    }
}