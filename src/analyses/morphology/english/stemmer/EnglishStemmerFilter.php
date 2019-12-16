<?php

namespace ftIndex\analyses\morphology\english\stemmer;

use ftIndex\analyses\TokenStream;

/**
 * Class EnglishStemmerFilter
 *
 * @package ftIndex\analyses\morphology\english\stemmer
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/13/19 12:57 PM
 */
class EnglishStemmerFilter extends TokenStream
{
    /** @var EnglishStemmer */
    private $englishStemmer;

    public function __construct(TokenStream $input, EnglishStemmer $englishStemmer)
    {
        parent::__construct($input);
        $this->englishStemmer = $englishStemmer;
    }

    final public function incrementToken(): bool
    {
        if (!$this->input->incrementToken()) {
            return false;
        }
        $s = $this->englishStemmer->getStemmedWord($this->termAttribute);
        $this->termAttribute = $s;

        return true;
    }

}