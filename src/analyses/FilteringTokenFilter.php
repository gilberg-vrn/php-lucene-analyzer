<?php

namespace ftIndex\analyses;

/**
 * Class FilteringTokenFilter
 *
 * @package ftIndex\analyses
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/9/19 1:47 PM
 */
abstract class FilteringTokenFilter
    extends TokenStream
{

    private $skippedPositions;

    /**
     * Create a new {@link FilteringTokenFilter}.
     *
     * @param TokenStream $input the {@link TokenStream} to consume
     */
    public function __construct(TokenStream $input)
    {
        parent::__construct($input);
    }

    /** Override this method and return if the current input token should be returned by {@link #incrementToken}. */
    protected abstract function accept(): bool;

    public final function incrementToken(): bool
    {
        $this->skippedPositions = 0;
        while ($this->input->incrementToken()) {
            if ($this->accept()) {
                if ($this->skippedPositions != 0) {
                    $this->posIncAttribute = $this->posIncAttribute + $this->skippedPositions;
                }
                return true;
            }
            $this->skippedPositions += $this->posIncAttribute;
        }

        // reached EOS -- return false
        return false;
    }
}