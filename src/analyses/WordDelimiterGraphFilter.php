<?php

namespace ftIndex\analyses;

use ftIndex\util\InPlaceMergeSorter;
use InvalidArgumentException;

/**
 * Splits words into subwords and performs optional transformations on subword
 * groups, producing a correct token graph so that e->g. {@link PhraseQuery} can
 * work correctly when this filter is used in the search-time analyzer.  Unlike
 * the deprecated {@link WordDelimiterFilter}, this token filter produces a
 * correct token graph as output.  However, it cannot consume an input token
 * graph correctly.
 *
 * <p>
 * Words are split into subwords with the following rules:
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
 * One use for {@link WordDelimiterGraphFilter} is to help match words with different
 * subword delimiters. For example, if the source text contained "wi-fi" one may
 * want "wifi" "WiFi" "wi-fi" "wi+fi" queries to all match. One way of doing so
 * is to specify combinations="1" in the analyzer used for indexing, and
 * combinations="0" (the default) in the analyzer used for querying. Given that
 * the current {@link StandardTokenizer} immediately removes many intra-word
 * delimiters, it is recommended that this filter be used after a tokenizer that
 * does not do this (such as {@link WhitespaceTokenizer}).
 */
final class WordDelimiterGraphFilter extends TokenStream
{

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
     * Causes maximum runs of number parts to be catenated:
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
     * Causes lowercase -&gt; uppercase transition to start a new subword.
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
    public $protWords;

    private $flags = 0;

    // packs start pos, end pos, start part, end part (= slice of the term text) for each buffered part:
    public $bufferedParts = []; //new int[16];
    public $bufferedLen;
    public $bufferedPos;

    // holds text for each buffered part, or null if it's a simple slice of the original term
    public $bufferedTermParts = []; //new char[4][];

    // used for iterating word delimiter breaks
    /** @var WordDelimiterIterator */
    private $iterator;

    // used for concatenating runs of similar typed subwords (word,number)
    /** @var WordDelimiterGraphConcatenation */
    private $concat = null; //new WordDelimiterGraphConcatenation();

    // number of subwords last output by concat.
    private $lastConcatCount;

    // used for catenate all
    /** @var WordDelimiterGraphConcatenation */
    private $concatAll = null; //new WordDelimiterGraphConcatenation();

    // used for accumulating position increment gaps so that we preserve incoming holes:
    private $accumPosInc;

    private $savedTermBuffer = ''; //new char[16];
    private $savedTermLength;
    private $savedStartOffset;
    private $savedEndOffset;
    private $savedState = [];
    private $lastStartOffset;

    // if length by start + end offsets doesn't match the term text then assume
    // this is a synonym and don't adjust the offsets.
    private $hasIllegalOffsets;

    public $wordPos;

    /**
     * Creates a new WordDelimiterGraphFilter
     *
     * @param TokenStream $input              TokenStream to be filtered
     * @param array       $charTypeTable      table containing character types
     * @param int         $configurationFlags Flags configuring the filter
     * @param array       $protWords          If not null is the set of tokens to protect from being delimited
     */
    public function __construct(TokenStream $input, array $charTypeTable = [], int $configurationFlags = null, array $protWords = [])
    {
        parent::__construct($input);
        if (($configurationFlags &
                ~(self::GENERATE_WORD_PARTS |
                    self::GENERATE_NUMBER_PARTS |
                    self::CATENATE_WORDS |
                    self::CATENATE_NUMBERS |
                    self::CATENATE_ALL |
                    self::PRESERVE_ORIGINAL |
                    self::SPLIT_ON_CASE_CHANGE |
                    self::SPLIT_ON_NUMERICS |
                    self::STEM_ENGLISH_POSSESSIVE)) != 0) {
            throw new InvalidArgumentException("flags contains unrecognized flag: " . $configurationFlags);
        } else {
            $configurationFlags = self::getDefaultFlags();
        }
        $this->flags = $configurationFlags;
        $this->protWords = $protWords;
        $this->iterator = new WordDelimiterIterator(
            $charTypeTable, $this->has(self::SPLIT_ON_CASE_CHANGE), $this->has(self::SPLIT_ON_NUMERICS), $this->has(self::STEM_ENGLISH_POSSESSIVE));
        $this->concat = new WordDelimiterGraphConcatenation($this);
        $this->concatAll = new WordDelimiterGraphConcatenation($this);
        $this->sorter = new PositionSorter($this);
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

    /** Iterates all words parts and concatenations, buffering up the term parts we should return. */
    private function bufferWordParts()
    {
        $this->saveState();

        // if length by start + end offsets doesn't match the term's text then set offsets for all our word parts/concats to the incoming
        // offsets.  this can happen if WDGF is applied to an injected synonym, or to a stem'd form, etc:
        $this->hasIllegalOffsets = ($this->savedEndOffset - $this->savedStartOffset != $this->savedTermLength);

        $this->bufferedLen = 0;
        $this->lastConcatCount = 0;
        $this->wordPos = 0;

        if ($this->iterator->isSingleWord()) {
            $this->buffer(null, $this->wordPos, $this->wordPos + 1, $this->iterator->current, $this->iterator->end);
            $this->wordPos++;
            $this->iterator->next();
        } else {

            // iterate all words parts, possibly buffering them, building up concatenations and possibly buffering them too:
            while ($this->iterator->end != WordDelimiterIterator::DONE) {
                $wordType = $this->iterator->type();

                // do we already have queued up incompatible concatenations?
                if ($this->concat->isNotEmpty() && ($this->concat->type & $wordType) == 0) {
                    $this->flushConcatenation($this->concat);
                }

                // add subwords depending upon options
                if ($this->shouldConcatenate($wordType)) {
                    $this->concatenate($this->concat);
                }

                // add all subwords (catenateAll)
                if ($this->has(self::CATENATE_ALL)) {
                    $this->concatenate($this->concatAll);
                }

                // if we should output the word or number part
                if ($this->shouldGenerateParts($wordType)) {
                    $this->buffer(null, $this->wordPos, $this->wordPos + 1, $this->iterator->current, $this->iterator->end);
                    $this->wordPos++;
                }
                $this->iterator->next();
            }

            if ($this->concat->isNotEmpty()) {
                // flush final concatenation
                $this->flushConcatenation($this->concat);
            }

            if ($this->concatAll->isNotEmpty()) {
                // only if we haven't output this same combo above, e->g. PowerShot with CATENATE_WORDS:
                if ($this->concatAll->subwordCount > $this->lastConcatCount) {
                    if ($this->wordPos == $this->concatAll->startPos) {
                        // we are not generating parts, so we must advance wordPos now
                        $this->wordPos++;
                    }
                    $this->concatAll->write();
                }
                $this->concatAll->clear();
            }
        }

        if ($this->has(self::PRESERVE_ORIGINAL)) {
            if ($this->wordPos == 0) {
                // can happen w/ strange flag combos and inputs :)
                $this->wordPos++;
            }
            // add the original token now so that we can set the correct end position
            $this->buffer(null, 0, $this->wordPos, 0, $this->savedTermLength);
        }

        $this->sorter->sort(0, $this->bufferedLen);
        $this->wordPos = 0;

        // set back to 0 for iterating from the buffer
        $this->bufferedPos = 0;
    }

    public function incrementToken(): bool
    {
        while (true) {
            if ($this->savedState == null) {

                // process a new input token
                if ($this->input->incrementToken() == false) {
                    return false;
                }

                $termLength = mb_strlen($this->termAttribute);
                $termBuffer = $this->termAttribute;

                $this->accumPosInc += $this->posIncAttribute;

                // iterate & cache all word parts up front:
                $this->iterator->setText($termBuffer, $termLength);
                $this->iterator->next();

                // word of no delimiters, or protected word: just return it
                if (($this->iterator->current == 0 && $this->iterator->end == $termLength) ||
                    ($this->protWords != null && isset($this->protWords[mb_substr($termBuffer, 0, $termLength)]))) {
                    $this->posIncAttribute = $this->accumPosInc;
                    $this->accumPosInc = 0;
                    return true;
                }

                // word of simply delimiters: swallow this token, creating a hole, and move on to next token
                if ($this->iterator->end == WordDelimiterIterator::DONE) {
                    if ($this->has(self::PRESERVE_ORIGINAL) == false) {
                        continue;
                    } else {
                        return true;
                    }
                }

                // otherwise, we have delimiters, process & buffer all parts:
                $this->bufferWordParts();
            }

            if ($this->bufferedPos < $this->bufferedLen) {
                $this->clearAttributes();
                $this->restoreState($this->savedState);

                $termPart = $this->bufferedTermParts[$this->bufferedPos];
                $startPos = $this->bufferedParts[4 * $this->bufferedPos];
                $endPos = $this->bufferedParts[4 * $this->bufferedPos + 1];
                $startPart = $this->bufferedParts[4 * $this->bufferedPos + 2];
                $endPart = $this->bufferedParts[4 * $this->bufferedPos + 3];
                $this->bufferedPos++;


                if ($this->hasIllegalOffsets) {
                    $startOffset = $this->savedStartOffset;
                    $endOffset = $this->savedEndOffset;
                } else {
                    $startOffset = $this->savedStartOffset + $startPart;
                    $endOffset = $this->savedStartOffset + $endPart;
                }

                // never let offsets go backwards:
                $startOffset = max($startOffset, $this->lastStartOffset);
                $endOffset = max($endOffset, $this->lastStartOffset);

                $this->offsetAttribute = [$startOffset, $endOffset];
                $this->lastStartOffset = $startOffset;

                if ($termPart == null) {
                    $this->termAttribute = mb_substr($this->savedTermBuffer, $startPart, $endPart - $startPart);
                } else {
                    $this->termAttribute = $termPart;
                }

                $this->posIncAttribute = ($this->accumPosInc + $startPos - $this->wordPos);
                $this->accumPosInc = 0;
                $this->posLenAttribute = ($endPos - $startPos);
                $this->wordPos = $startPos;
                return true;
            }

            // no saved concatenations, on to the next input word
            $this->savedState = null;
        }
    }

    public function reset()
    {
        $this->accumPosInc = 0;
        $this->savedState = null;
        $this->lastStartOffset = 0;
        $this->concat->clear();
        $this->concatAll->clear();
    }

    // ================================================= Helper Methods ================================================


    /** @var PositionSorter */
    protected $sorter = null; //new PositionSorter();

    /**
     * startPos, endPos -> graph start/end position
     * startPart, endPart -> slice of the original term for this part
     */

//  void buffer(int startPos, int endPos, int startPart, int endPart) {
//    buffer(null, startPos, endPos, startPart, endPart);
//}

    /**
     * a null termPart means it's a simple slice of the original term
     *
     * @param string $termPart
     * @param int    $startPos
     * @param int    $endPos
     * @param int    $startPart
     * @param int    $endPart
     */
    public function buffer($termPart, int $startPos, int $endPos, int $startPart, int $endPart)
    {
        /*
        System->out->println("buffer: pos=" + startPos + "-" + endPos + " part=" + startPart + "-" + endPart);
        if (termPart != null) {
          System->out->println("  termIn=" + new String(termPart));
        } else {
          System->out->println("  term=" + new String(savedTermBuffer, startPart, endPart-startPart));
        }
        */
//    assert endPos > startPos: "startPos=" + startPos + " endPos=" + endPos;
//    assert endPart > startPart || (endPart == 0 && startPart == 0 && savedTermLength == 0): "startPart=" + startPart + " endPart=" + endPart;
//    if (($this->bufferedLen+1)*4 > count($this->bufferedParts)) {
//        bufferedParts = ArrayUtil->grow(bufferedParts, (bufferedLen+1)*4);
//    }
//    if (count($this->bufferedTermParts) == $this->bufferedLen) {
//        $newSize = ArrayUtil->oversize(bufferedLen+1, RamUsageEstimator->NUM_BYTES_OBJECT_REF);
//      char[][] newArray = new char[newSize][];
//      System->arraycopy(bufferedTermParts, 0, newArray, 0, bufferedTermParts->length);
//      bufferedTermParts = newArray;
//    }
        $this->bufferedTermParts[$this->bufferedLen] = $termPart;
        $this->bufferedParts[$this->bufferedLen * 4] = $startPos;
        $this->bufferedParts[$this->bufferedLen * 4 + 1] = $endPos;
        $this->bufferedParts[$this->bufferedLen * 4 + 2] = $startPart;
        $this->bufferedParts[$this->bufferedLen * 4 + 3] = $endPart;
        $this->bufferedLen++;
    }

    /**
     * Saves the existing attribute states
     */
    private function saveState()
    {
        $this->savedTermLength = mb_strlen($this->termAttribute);
        $this->savedStartOffset = $this->offsetAttribute[0];
        $this->savedEndOffset = $this->offsetAttribute[1];
        $this->savedState = $this->captureState();

//    if (count($this->savedTermBuffer) < $this->savedTermLength) {
//        $this->savedTermBuffer = new char[ArrayUtil->oversize(savedTermLength, Character->BYTES)];
//    }

//    System->arraycopy(termAttribute->buffer(), 0, $this->savedTermBuffer, 0, savedTermLength);
        $this->savedTermBuffer = $this->termAttribute;
    }

    /**
     * Flushes the given WordDelimiterGraphConcatenation by either writing its concat and then clearing, or just clearing.
     *
     * @param WordDelimiterGraphConcatenation $concat WordDelimiterGraphConcatenation that will be flushed
     */
    private function flushConcatenation(WordDelimiterGraphConcatenation $concat)
    {
        if ($this->wordPos == $this->concat->startPos) {
            // we are not generating parts, so we must advance wordPos now
            $this->wordPos++;
        }
        $this->lastConcatCount = $this->concat->subwordCount;
        if ($concat->subwordCount != 1 || $this->shouldGenerateParts($concat->type) == false) {
            $concat->write();
        }
        $concat->clear();
    }

    /**
     * Determines whether to concatenate a word or number if the current word is the given type
     *
     * @param int $wordType Type of the current word used to determine if it should be concatenated
     *
     * @return bool {@code true} if concatenation should occur, {@code false} otherwise
     */
    private function shouldConcatenate(int $wordType): bool
    {
        return ($this->has(self::CATENATE_WORDS) && WordDelimiterIterator::isAlpha($wordType)) || ($this->has(self::CATENATE_NUMBERS) && WordDelimiterIterator::isDigit($wordType));
    }

    /**
     * Determines whether a word/number part should be generated for a word of the given type
     *
     * @param int $wordType Type of the word used to determine if a word/number part should be generated
     *
     * @return bool {@code true} if a word/number part should be generated, {@code false} otherwise
     */
    private function shouldGenerateParts(int $wordType): bool
    {
        return ($this->has(self::GENERATE_WORD_PARTS) && WordDelimiterIterator::isAlpha($wordType)) || ($this->has(self::GENERATE_NUMBER_PARTS) && WordDelimiterIterator::isDigit($wordType));
    }

    /**
     * Concatenates the saved buffer to the given WordDelimiterGraphConcatenation
     *
     * @param WordDelimiterGraphConcatenation $concatenation WordDelimiterGraphConcatenation to concatenate the buffer to
     */
    private function concatenate(WordDelimiterGraphConcatenation $concatenation)
    {
        if ($concatenation->isEmpty()) {
            $concatenation->type = $this->iterator->type();
            $concatenation->startPart = $this->iterator->current;
            $concatenation->startPos = $this->wordPos;
        }
        $concatenation->append($this->savedTermBuffer, $this->iterator->current, $this->iterator->end - $this->iterator->current);
        $concatenation->endPart = $this->iterator->end;
    }

    /**
     * Determines whether the given flag is set
     *
     * @param int $flag Flag to see if set
     *
     * @return bool {@code true} if flag is set
     */
    private function has(int $flag): bool
    {
        return ($this->flags & $flag) != 0;
    }

    // ================================================= Inner Classes =================================================
}


/**
 * A WDF concatenated 'run'
 */
final class WordDelimiterGraphConcatenation
{
    public $buffer = ''; //new StringBuilder();
    public $startPart;
    public $endPart;
    public $startPos;
    public $type;
    public $subwordCount;
    /** @var WordDelimiterGraphFilter */
    public $filter;

    public function __construct($filter)
    {
        $this->filter = $filter;
    }

    /**
     * Appends the given text of the given length, to the concatenation at the given offset
     *
     * @param string $text   Text to append
     * @param int    $offset Offset in the concatenation to add the text
     * @param int    $length Length of the text to append
     */
    public function append(string $text, int $offset, int $length)
    {
        unset($offset, $length);

        $this->buffer .= $text;
        $this->subwordCount++;
    }

    /**
     * Writes the concatenation to part buffer
     */
    public function write()
    {
        $termPart = mb_substr($this->buffer, 0); //new char[buffer->length()];

        $this->filter->buffer($termPart, $this->startPos, $this->filter->wordPos, $this->startPart, $this->endPart);
    }

    /**
     * Determines if the concatenation is empty
     *
     * @return bool {@code true} if the concatenation is empty, {@code false} otherwise
     */
    public function isEmpty(): bool
    {
        return mb_strlen($this->buffer) == 0;
    }

    public function isNotEmpty(): bool
    {
        return $this->isEmpty() == false;
    }

    /**
     * Clears the concatenation and resets its state
     */
    public function clear()
    {
        $this->buffer = '';
        $this->startPart = $this->endPart = $this->type = $this->subwordCount = 0;
    }
}

// questions:
// negative numbers?  -42 indexed as just 42?
// dollar sign?  $42
// percent sign?  33%
// downsides:  if source text is "powershot" then a query of "PowerShot" won't match!

class PositionSorter extends InPlaceMergeSorter
{
    public $filter;

    public function __construct($filter)
    {
        parent::__construct();
        $this->filter = $filter;
    }

    protected function compare(int $i, int $j): int
    {
        // sort by smaller start position
        $iPosStart = $this->filter->bufferedParts[4 * $i];
        $jPosStart = $this->filter->bufferedParts[4 * $j];
        $cmp = $iPosStart <=> $jPosStart;
        if ($cmp != 0) {
            return $cmp;
        }

        // tie break by longest pos length:
        $iPosEnd = $this->filter->bufferedParts[4 * $i + 1];
        $jPosEnd = $this->filter->bufferedParts[4 * $j + 1];
        return $jPosEnd <=> $iPosEnd;
    }

    protected function swap(int $i, int $j)
    {
        $iOffset = 4 * $i;
        $jOffset = 4 * $j;
        for ($x = 0; $x < 4; $x++) {
            $tmp = $this->filter->bufferedParts[$iOffset + $x];
            $this->filter->bufferedParts[$iOffset + $x] = $this->filter->bufferedParts[$jOffset + $x];
            $this->filter->bufferedParts[$jOffset + $x] = $tmp;
        }

        $tmp2 = $this->filter->bufferedTermParts[$i];
        $this->filter->bufferedTermParts[$i] = $this->filter->bufferedTermParts[$j];
        $this->filter->bufferedTermParts[$j] = $tmp2;
    }
}