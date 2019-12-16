<?php

namespace ftIndex\analyses;

/**
 * Class StopFilter
 *
 * @package ftIndex\analyses
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/9/19 1:46 PM
 */
class StopFilter
    extends FilteringTokenFilter
{

    private $stopWords = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by',
        'for', 'if', 'in', 'into', 'is', 'it',
        'no', 'not', 'of', 'on', 'or', 'such',
        'that', 'the', 'their', 'then', 'there', 'these',
        'they', 'this', 'to', 'was', 'will', 'with'
    ];

    public function __construct(TokenStream $input, $stopWords)
    {
        parent::__construct($input);
        $this->stopWords = $stopWords;
    }

    /** Override this method and return if the current input token should be returned by {@link #incrementToken}. */
    protected function accept(): bool
    {
        return !isset($this->stopWords[$this->termAttribute]);
    }
}