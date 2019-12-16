<?php

namespace ftIndex\analyses;

/**
 * Class LowerCaseFilter
 *
 * @package ftIndex\analyses
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/9/19 1:44 PM
 */
class LowerCaseFilter
    extends TokenStream
{

    public function incrementToken(): bool
    {
        if ($this->input->incrementToken()) {
            $this->termAttribute = mb_strtolower($this->termAttribute);
            return true;
        } else {
            return false;
        }
    }
}