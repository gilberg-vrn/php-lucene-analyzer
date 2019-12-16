<?php

namespace ftIndex\analyses\charfilter;

use ftIndex\analyses\hunspell\IllegalArgumentException;
use ftIndex\analyses\hunspell\SimpleFST;

/**
 * Class NormalizeCharMap
 *
 * @package ftIndex\analyses\charfilter
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    10/3/19 8:02 PM
 */
class NormalizeCharMapBuilder
{
    private $pendingPairs = [];

    /** Records a replacement to be applied to the input
     *  stream.  Whenever <code>singleMatch</code> occurs in
     *  the input, it will be replaced with
     *  <code>replacement</code>.
     *
     * @param match input String to be replaced
     * @param replacement output String
     *
     * @throws IllegalArgumentException if
     * <code>match</code> is the empty string, or was
     * already previously added
     */
    public function add(string $match, string $replacement)
    {
        if (mb_strlen($match) == 0) {
            throw new IllegalArgumentException("cannot match the empty string");
        }
        if (isset($this->pendingPairs[$match])) {
            throw new IllegalArgumentException("match \"" . $match . "\" was already added");
        }
        $this->pendingPairs[$match] = $replacement;
    }

    /** Builds the NormalizeCharMap; call this once you
     *  are done calling {@link #add}. */
    public function build(): NormalizeCharMap
    {

        $map = new SimpleFST();
//          final Outputs<CharsRef> outputs = CharSequenceOutputs.getSingleton();
//        final org.apache.lucene.util.fst.Builder<CharsRef> builder = new org.apache.lucene.util.fst.Builder<>(FST.INPUT_TYPE.BYTE2, outputs);
//        final IntsRefBuilder scratch = new IntsRefBuilder();
        foreach ($this->pendingPairs as $match => $replacement) {
            $map->addWord($match, $replacement);
        }
        $this->pendingPairs = [];

        return new NormalizeCharMap($map);
    }
}