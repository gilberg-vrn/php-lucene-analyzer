<?php

namespace ftIndex\fst;

use ftIndex\store\DataInput;
use ftIndex\store\DataOutput;

/**
 * Class FSTStore
 *
 * @package mpcmf\apps\pl\libraries\morphology\src\store
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    3/26/19 2:02 PM
 */
interface FSTStore
{
    public function init(DataInput $in, $numBytes);

    public function getReverseBytesReader();

    public function writeTo(DataOutput $out);
}