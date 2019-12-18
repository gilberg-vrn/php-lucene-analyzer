<?php

namespace ftIndex\analyses;

use ftIndex\util\InPlaceMergeSorter;

/**
 * Splits words into subwords and performs optional transformations on subword
 * groups. Words are split into subwords with the following rules:
 * <ul>
 * <li>split on intra-word delimiters (by default, all non alpha-numeric
 * characters): <code>"Wi-Fi"</code> &#8594; <code>"Wi", "Fi"</code></li>
 * <li>split on case transitions: <code>"PowerShot"</code> &#8594;
 * <code>"Power", "Shot"</code></li>
 * <li>split on letter-number transitions: <code>"SD500"</code> &#8594;
 * <code>"SD", "500"</code></li>
 * <li>leading and trailing intra-word delimiters on each subword are ignored:
 * <code>"//hello---there, 'dude'"</code> &#8594;
 * <code>"hello", "there", "dude"</code></li>
 * <li>trailing "'s" are removed for each subword: <code>"O'Neil's"</code>
 * &#8594; <code>"O", "Neil"</code>
 * <ul>
 * <li>Note: this step isn't performed in a separate filter because of possible
 * subword combinations.</li>
 * </ul>
 * </li>
 * </ul>
 *
 * The <b>combinations</b> parameter affects how subwords are combined:
 * <ul>
 * <li>combinations="0" causes no subword combinations: <code>"PowerShot"</code>
 * &#8594; <code>0:"Power", 1:"Shot"</code> (0 and 1 are the token positions)</li>
 * <li>combinations="1" means that in addition to the subwords, maximum runs of
 * non-numeric subwords are catenated and produced at the same position of the
 * last subword in the run:
 * <ul>
 * <li><code>"PowerShot"</code> &#8594;
 * <code>0:"Power", 1:"Shot" 1:"PowerShot"</code></li>
 * <li><code>"A's+B's&amp;C's"</code> &gt; <code>0:"A", 1:"B", 2:"C", 2:"ABC"</code>
 * </li>
 * <li><code>"Super-Duper-XL500-42-AutoCoder!"</code> &#8594;
 * <code>0:"Super", 1:"Duper", 2:"XL", 2:"SuperDuperXL", 3:"500" 4:"42", 5:"Auto", 6:"Coder", 6:"AutoCoder"</code>
 * </li>
 * </ul>
 * </li>
 * </ul>
 * One use for {@link WordDelimiterFilter} is to help match words with different
 * subword delimiters. For example, if the source text contained "wi-fi" one may
 * want "wifi" "WiFi" "wi-fi" "wi+fi" queries to all match. One way of doing so
 * is to specify combinations="1" in the analyzer used for indexing, and
 * combinations="0" (the default) in the analyzer used for querying. Given that
 * the current {@link StandardTokenizer} immediately removes many intra-word
 * delimiters, it is recommended that this filter be used after a tokenizer that
 * does not do this (such as {@link WhitespaceTokenizer}).
 *
 * @deprecated Use {@link WordDelimiterGraphFilter} instead: it produces a correct
 * token graph so that e.g. {@link PhraseQuery} works correctly when it's used in
 * the search time analyzer.
 */
final class WordDelimiterFilter extends TokenStream
{

    const LOWER         = 0x01;
    const UPPER         = 0x02;
    const DIGIT         = 0x04;
    const SUBWORD_DELIM = 0x08;

    // combinations: for testing, not for setting bits
    const ALPHA    = 0x03;
    const ALPHANUM = 0x07;

    /**
     * Causes parts of words to be generated:
     * <p>
     * "PowerShot" =&gt; "Power" "Shot"
     */
    const GENERATE_WORD_PARTS = 1;

    /**
     * Causes number subwords to be generated:
     * <p>
     * "500-42" =&gt; "500" "42"
     */
    const GENERATE_NUMBER_PARTS = 2;

    /**
     * Causes maximum runs of word parts to be catenated:
     * <p>
     * "wi-fi" =&gt; "wifi"
     */
    const CATENATE_WORDS = 4;

    /**
     * Causes maximum runs of word parts to be catenated:
     * <p>
     * "500-42" =&gt; "50042"
     */
    const CATENATE_NUMBERS = 8;

    /**
     * Causes all subword parts to be catenated:
     * <p>
     * "wi-fi-4000" =&gt; "wifi4000"
     */
    const CATENATE_ALL = 16;

    /**
     * Causes original words are preserved and added to the subword list (Defaults to false)
     * <p>
     * "500-42" =&gt; "500" "42" "500-42"
     */
    const PRESERVE_ORIGINAL = 32;

    /**
     * If not set, causes case changes to be ignored (subwords will only be generated
     * given SUBWORD_DELIM tokens)
     */
    const SPLIT_ON_CASE_CHANGE = 64;

    /**
     * If not set, causes numeric changes to be ignored (subwords will only be generated
     * given SUBWORD_DELIM tokens).
     */
    const SPLIT_ON_NUMERICS = 128;

    /**
     * Causes trailing "'s" to be removed for each subword
     * <p>
     * "O'Neil's" =&gt; "O", "Neil"
     */
    const STEM_ENGLISH_POSSESSIVE = 256;

    /**
     * If not null is the set of tokens to protect from being delimited
     *
     */
    protected $protWords;
    /**
     * @var TokenStream
     */
    public $input;

    /** @var int */
    private $flags;

    // used for iterating word delimiter breaks
    /** @var WordDelimiterIterator */
    protected $iterator;

    // used for concatenating runs of similar typed subwords (word,number)
    /** @var WordDelimiterConcatenation */
    private $concat = null; //new WordDelimiterConcatenation();
    // number of subwords last output by concat.
    private $lastConcatCount = 0;

    // used for catenate all
    /** @var WordDelimiterConcatenation */
    private $concatAll = null; //new WordDelimiterConcatenation();

    // used for accumulating position increment gaps
    public $accumPosInc = 0;

    private $savedBuffer = [];
    public $savedStartOffset;
    public $savedEndOffset;
    public $savedType;
    public $hasSavedState = false;
    // if length by start + end offsets doesn't match the term text then assume
    // this is a synonym and don't adjust the offsets.
    public $hasIllegalOffsets = false;

    // for a run of the same subword type within a word, have we output anything?
    private $hasOutputToken = false;
    // when preserve original is on, have we output any token following it?
    // this token must have posInc=0!
    private $hasOutputFollowingOriginal = false;

    /**
     * Creates a new WordDelimiterFilter
     *
     * @param TokenStream $in TokenStream to be filtered
     * @param array $charTypeTable table containing character types
     * @param int $configurationFlags Flags configuring the filter
     * @param array $protWords If not null is the set of tokens to protect from being delimited
     */
    public function __construct(TokenStream $in, array $charTypeTable = null, int $configurationFlags = null, array $protWords = [])
    {
        parent::__construct($in);
        $this->flags = $configurationFlags ?: self::getDefaultFlags();
        $this->protWords = $protWords;
        $this->iterator = new WordDelimiterIterator(
            $charTypeTable, $this->has(self::SPLIT_ON_CASE_CHANGE), $this->has(self::SPLIT_ON_NUMERICS), $this->has(self::STEM_ENGLISH_POSSESSIVE));
        $this->concat = new WordDelimiterConcatenation($this);
        $this->concatAll = new WordDelimiterConcatenation($this);
        $this->sorter = new OffsetSorter($this);
    }

    public static function getDefaultFlags()
    {
        $flags = 0;
        $flags |= self::GENERATE_WORD_PARTS;
        $flags |= self::GENERATE_NUMBER_PARTS;
        $flags |= self::SPLIT_ON_CASE_CHANGE;
        $flags |= self::SPLIT_ON_NUMERICS;
        $flags |= self::STEM_ENGLISH_POSSESSIVE;

        $flags |= self::PRESERVE_ORIGINAL; //NOT DEFAULT IN LUCENE, but used for us as default flag

        return $flags;
    }

    public function incrementToken(): bool
    {
        while (true) {
            if (!$this->hasSavedState) {
                // process a new input word
                if (!$this->input->incrementToken()) {
                    return false;
                }
                
                $termBuffer = $this->termAttribute;

                $this->accumPosInc += $this->posIncAttribute;

                $this->iterator->setText($termBuffer);
                $this->iterator->next();

                // word of no delimiters, or protected word: just return it
                if (($this->iterator->current == 0 && $this->iterator->end == $this->iterator->length) ||
                    ($this->protWords !== null && isset($this->protWords[$termBuffer]))) {
                    $this->posIncAttribute = $this->accumPosInc;
                    $this->accumPosInc = 0;
                    $this->first = false;
                    return true;
                }

                // word of simply delimiters
                if ($this->iterator->end == WordDelimiterIterator::DONE && !$this->has(self::PRESERVE_ORIGINAL)) {
                    // if the posInc is 1, simply ignore it in the accumulation
                    // TODO: proper hole adjustment (FilteringTokenFilter-like) instead of this previous logic!
                    if ($this->posIncAttribute == 1 && !$this->first) {
                        $this->accumPosInc--;
                    }
                    continue;
                }

                $this->saveState();

                $this->hasOutputToken = false;
                $this->hasOutputFollowingOriginal = !$this->has(self::PRESERVE_ORIGINAL);
                $this->lastConcatCount = 0;

                if ($this->has(self::PRESERVE_ORIGINAL)) {
                    $this->posIncAttribute = $this->accumPosInc;
                    $this->accumPosInc = 0;
                    $this->first = false;
                    return true;
                }
            }

            // at the end of the string, output any concatenations
            if ($this->iterator->end == WordDelimiterIterator::DONE) {
                if (!$this->concat->isEmpty()) {
                    if ($this->flushConcatenation($this->concat)) {
                        $this->buffer();
                        continue;
                    }
                }

                if (!$this->concatAll->isEmpty()) {
                    // only if we haven't output this same combo above!
                    if ($this->concatAll->subwordCount > $this->lastConcatCount) {
                        $this->concatAll->writeAndClear();
                        $this->buffer();
                        continue;
                    }
                    $this->concatAll->clear();
                }

                if ($this->bufferedPos < $this->bufferedLen) {
                    if ($this->bufferedPos == 0) {
                        $this->sorter->sort(0, $this->bufferedLen);
                    }
                    $this->clearAttributes();
                    $this->restoreState($this->buffered[$this->bufferedPos++]);
                    if ($this->first && $this->posIncAttribute == 0) {
                        // can easily happen with strange combinations (e.g. not outputting numbers, but concat-all)
                        $this->posIncAttribute = 1;
                    }
                    $this->first = false;
                    return true;
                }

                // no saved concatenations, on to the next input word
                $this->bufferedPos = $this->bufferedLen = 0;
                $this->hasSavedState = false;
                continue;
            }

            // word surrounded by delimiters: always output
            if ($this->iterator->isSingleWord()) {
                $this->generatePart(true);
                $this->iterator->next();
                $this->first = false;
                return true;
            }

            $wordType = $this->iterator->type();

            // do we already have queued up incompatible concatenations?
            if (!$this->concat->isEmpty() && ($this->concat->type & $wordType) == 0) {
                if ($this->flushConcatenation($this->concat)) {
                    $this->hasOutputToken = false;
                    $this->buffer();
                    continue;
                }
                $this->hasOutputToken = false;
            }

            // add subwords depending upon options
            if ($this->shouldConcatenate($wordType)) {
                if ($this->concat->isEmpty()) {
                    $this->concat->type = $wordType;
                }
                $this->concatenate($this->concat);
            }

            // add all subwords (catenateAll)
            if ($this->has(self::CATENATE_ALL)) {
                $this->concatenate($this->concatAll);
            }

            // if we should output the word or number part
            if ($this->shouldGenerateParts($wordType)) {
                $this->generatePart(false);
                $this->buffer();
            }

            $this->iterator->next();
        }
    }

    public function reset()
    {
//    parent::reset();
        $this->hasSavedState = false;
        $this->concat->clear();
        $this->concatAll->clear();
        $this->accumPosInc = $this->bufferedPos = $this->bufferedLen = 0;
        $this->first = true;
    }

    // ================================================= Helper Methods ================================================


    public $buffered = []; //new AttributeSource.State[8];
    /** @var int[] */
    public $startOff = []; //new int[8];
    /** @var int[] */
    public $posInc = []; //new int[8];
    /** @var int */
    private $bufferedLen = 0;
    /** @var int */
    private $bufferedPos = 0;
    /** @var bool */
    private $first;

    public $state = [];

    /** @var OffsetSorter $sorter */
    protected $sorter = null; //new OffsetSorter();

    private function buffer()
    {
        $this->startOff[$this->bufferedLen] = $this->offsetAttribute[0];
        $this->posInc[$this->bufferedLen] = $this->posIncAttribute;
        $this->buffered[$this->bufferedLen] = $this->captureState();
        $this->bufferedLen++;
    }

    /**
     * Saves the existing attribute states
     */
    private function saveState()
    {
        // otherwise, we have delimiters, save state
        $this->savedStartOffset = $this->offsetAttribute[0];
        $this->savedEndOffset = $this->offsetAttribute[1];
        // if length by start + end offsets doesn't match the term text then assume this is a synonym and don't adjust the offsets.
        $this->hasIllegalOffsets = ($this->savedEndOffset - $this->savedStartOffset != mb_strlen($this->termAttribute));
        $this->savedType = $this->typeAttribute;

        $this->savedBuffer = preg_split('//u', $this->termAttribute, -1, PREG_SPLIT_NO_EMPTY);
        $this->iterator->text = $this->savedBuffer;

        $this->hasSavedState = true;
    }

    /**
     * Flushes the given WordDelimiterConcatenation by either writing its concat and then clearing, or just clearing.
     *
     * @param WordDelimiterConcatenation $concatenation WordDelimiterConcatenation that will be flushed
     *
     * @return {@code true} if the concatenation was written before it was cleared, {@code false} otherwise
     */
    private function flushConcatenation(WordDelimiterConcatenation $concatenation): bool
    {
        $this->lastConcatCount = $concatenation->subwordCount;
        if ($concatenation->subwordCount != 1 || !$this->shouldGenerateParts($concatenation->type)) {
            $concatenation->writeAndClear();
            return true;
        }
        $concatenation->clear();
        return false;
    }

    /**
     * Determines whether to concatenate a word or number if the current word is the given type
     *
     * @param int $wordType Type of the current word used to determine if it should be concatenated
     *
     * @return {@code true} if concatenation should occur, {@code false} otherwise
     */
    private function shouldConcatenate(int $wordType): bool
    {
        return ($this->has(self::CATENATE_WORDS) && $this->isAlpha($wordType)) || ($this->has(self::CATENATE_NUMBERS) && $this->isDigit($wordType));
    }

    /**
     * Determines whether a word/number part should be generated for a word of the given type
     *
     * @param int $wordType Type of the word used to determine if a word/number part should be generated
     *
     * @return {@code true} if a word/number part should be generated, {@code false} otherwise
     */
    private function shouldGenerateParts(int $wordType): int
    {
        return ($this->has(self::GENERATE_WORD_PARTS) && $this->isAlpha($wordType)) || ($this->has(self::GENERATE_NUMBER_PARTS) && $this->isDigit($wordType));
    }

    /**
     * Concatenates the saved buffer to the given WordDelimiterConcatenation
     *
     * @param WordDelimiterConcatenation $concatenation WordDelimiterConcatenation to concatenate the buffer to
     */
    private function concatenate(WordDelimiterConcatenation $concatenation)
    {
        if ($concatenation->isEmpty()) {
            $concatenation->startOffset = $this->savedStartOffset + $this->iterator->current;
        }
        $concatenation->append($this->savedBuffer, $this->iterator->current, $this->iterator->end - $this->iterator->current);
        $concatenation->endOffset = $this->savedStartOffset + $this->iterator->end;
    }

    /**
     * Generates a word/number part, updating the appropriate attributes
     *
     * @param bool $isSingleWord {@code true} if the generation is occurring from a single word, {@code false} otherwise
     */
    private function generatePart(bool $isSingleWord)
    {
        $this->clearAttributes();

        $this->termAttribute = implode('', array_slice($this->savedBuffer, $this->iterator->current, $this->iterator->end - $this->iterator->current));
        $startOffset = $this->savedStartOffset + $this->iterator->current;
        $endOffset = $this->savedStartOffset + $this->iterator->end;

        if ($this->hasIllegalOffsets) {
            // historically this filter did this regardless for 'isSingleWord',
            // but we must do a sanity check:
            if ($isSingleWord && $startOffset <= $this->savedEndOffset) {
                $this->offsetAttribute = [$startOffset, $this->savedEndOffset];
            } else {
                $this->offsetAttribute = [$this->savedStartOffset, $this->savedEndOffset];
            }
        } else {
            $this->offsetAttribute = [$startOffset, $endOffset];
        }
        $this->posIncAttribute = $this->position(false);
        $this->typeAttribute = $this->savedType;
    }

    /**
     * Get the position increment gap for a subword or concatenation
     *
     * @param bool $inject true if this token wants to be injected
     *
     * @return int position increment gap
     */
    public function position(bool $inject): int
    {
        $posInc = $this->accumPosInc;

        if ($this->hasOutputToken) {
            $this->accumPosInc = 0;
            return $inject ? 0 : max(1, $posInc);
        }

        $this->hasOutputToken = true;

        if (!$this->hasOutputFollowingOriginal) {
            // the first token following the original is 0 regardless
            $this->hasOutputFollowingOriginal = true;
            return 0;
        }
        // clear the accumulated position increment
        $this->accumPosInc = 0;
        return max(1, $posInc);
    }

    /**
     * Checks if the given word type includes {@link #ALPHA}
     *
     * @param int $type Word type to check
     *
     * @return bool {@code true} if the type contains ALPHA, {@code false} otherwise
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

    /**
     * Determines whether the given flag is set
     *
     * @param int $flag Flag to see if set
     *
     * @return {@code true} if flag is set
     */
    private function has(int $flag): bool
    {
        return ($this->flags & $flag) != 0;
    }
}

/**
 * A WDF concatenated 'run'
 */
final class WordDelimiterConcatenation
{
    public $buffer = "";
    public $startOffset;
    public $endOffset;
    public $type;
    public $subwordCount;

    /** @var WordDelimiterFilter */
    protected $parent;

    public function __construct($parent)
    {
        $this->parent = $parent;
    }

    /**
     * Appends the given text of the given length, to the concetenation at the given offset
     *
     * @param array $text   Text to append
     * @param int    $offset Offset in the concetenation to add the text
     * @param int    $length Length of the text to append
     */
    public function append($text, int $offset, int $length)
    {
        $this->buffer .= implode('', array_slice($text, $offset, $length));
        $this->subwordCount++;
    }

    /**
     * Writes the concatenation to the attributes
     */
    public function write()
    {
        $this->parent->clearAttributes();

        $termbuffer = $this->parent->termAttribute;

//      buffer.getChars(0, buffer.length(), termbuffer, 0);
//      termAttribute.setLength(buffer.length());

        if ($this->parent->hasIllegalOffsets) {
            $this->parent->offsetAttribute = [$this->parent->savedStartOffset, $this->parent->savedEndOffset];
        } else {
            $this->parent->offsetAttribute = [$this->startOffset, $this->endOffset];
        }
        $this->parent->posIncAttribute = $this->parent->position(true);
        $this->parent->typeAttribute = $this->parent->savedType;
        $this->parent->accumPosInc = 0;
    }

    /**
     * Determines if the concatenation is empty
     *
     * @return {@code true} if the concatenation is empty, {@code false} otherwise
     */
    public function isEmpty(): bool
    {
        return mb_strlen($this->buffer) === 0;
    }

    /**
     * Clears the concatenation and resets its state
     */
    public function clear()
    {
        $this->buffer = '';
        $this->startOffset = $this->endOffset = $this->type = $this->subwordCount = 0;
    }

    /**
     * Convenience method for the common scenario of having to write the concetenation and then clearing its state
     */
    public function writeAndClear()
    {
        $this->write();
        $this->clear();
    }
}


class OffsetSorter extends InPlaceMergeSorter
{

    protected $filter;

    public function __construct(WordDelimiterFilter $filter)
    {
        parent::__construct();
        $this->filter = $filter;
    }

    protected function compare(int $i, int $j): int
    {
        $cmp = $this->filter->startOff[$i] <=> $this->filter->startOff[$j];
        if ($cmp == 0) {
            $cmp = $this->filter->posInc[$j] <=> $this->filter->posInc[$i];
        }
        return $cmp;
    }

    protected function swap(int $i, int $j)
    {
        $tmp = $this->filter->buffered[$i];
        $this->filter->buffered[$i] = $this->filter->buffered[$j];
        $this->filter->buffered[$j] = $tmp;

        $tmp2 = $this->filter->startOff[$i];
        $this->filter->startOff[$i] = $this->filter->startOff[$j];
        $this->filter->startOff[$j] = $tmp2;

        $tmp2 = $this->filter->posInc[$i];
        $this->filter->posInc[$i] = $this->filter->posInc[$j];
        $this->filter->posInc[$j] = $tmp2;
    }
}
// questions:
// negative numbers?  -42 indexed as just 42?
// dollar sign?  $42
// percent sign?  33%
// downsides:  if source text is "powershot" then a query of "PowerShot" won't match!
