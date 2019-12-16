<?php

namespace ftIndex\analyses\hunspell;

use ftIndex\analyses\TokenStream;

/**
 * Class HunspellStemFilter
 *
 * @package ftIndex\analyses\hunspell
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/13/19 2:51 PM
 */
final class HunspellStemFilter extends TokenStream {

    /** @var Stemmer */
  private $stemmer;

  private $buffer;
  private $savedState;

  private $dedup;
  private $longestOnly;

  /**
   * Creates a new HunspellStemFilter that will stem tokens from the given TokenStream using affix rules in the provided
   * Dictionary
   *
   * @param TokenStream $input TokenStream whose tokens will be stemmed
   * @param Dictionary $dictionary HunspellDictionary containing the affix rules and words that will be used to stem the tokens
   * @param bool $dedup
   * @param bool $longestOnly true if only the longest term should be output.
   */
public function __construct(TokenStream $input, Dictionary $dictionary, bool $dedup = true, bool $longestOnly = false) {
    parent::__construct($input);
    $this->dedup = $dedup && $longestOnly == false; // don't waste time deduping if longestOnly is set
    $this->stemmer = new Stemmer($dictionary);
    $this->longestOnly = $longestOnly;
}

    public function incrementToken(): bool
    {
        if ($this->buffer != null && !empty($this->buffer)) {
            $nextStem = array_shift($this->buffer);
            $this->restoreState($this->savedState);
            $this->posIncAttribute = 0;
            $this->termAttribute = $nextStem;
            return true;
        }

        if (!$this->input->incrementToken()) {
            return false;
        }

        if ($this->keywordAttribute) {
            return true;
        }

        $this->buffer = $this->dedup ? $this->stemmer->uniqueStems($this->termAttribute, mb_strlen($this->termAttribute)) : $this->stemmer->stemWord2($this->termAttribute, mb_strlen($this->termAttribute));

        if (empty($this->buffer)) { // we do not know this word, return it unchanged
            return true;
        }

        if ($this->longestOnly && count($this->buffer) > 1) {
            usort($this->buffer, function ($a, $b) {
                return mb_strlen($b) <=> mb_strlen($a);
            });
        }

        $stem = array_shift($this->buffer);
        $this->termAttribute = $stem;

        if ($this->longestOnly) {
            $this->buffer = [];
        } else {
            if (!empty($this->buffer)) {
                $this->savedState = $this->captureState();
            }
        }

        return true;
    }

    public function reset()
    {
//    parent::reset();
        $this->buffer = null;
    }
}
