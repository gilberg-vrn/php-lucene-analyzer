<?php

namespace ftIndex\analyses\hunspell;

use ftIndex\fst\BytesStore;
use ftIndex\fst\NodeHash;
use ftIndex\fst\Outputs;
use ftIndex\fst\Util;

/**
 * Class Builder
 *
 * @package mpcmf\apps\pl\libraries\morphology\src
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    3/27/19 4:33 PM
 */
class Builder
{

    /**
     * @var NodeHash
     */
    private $dedupHash;

    /**
     * @var FST
     */
    public $fst;

    /**
     * @var mixed
     */
    public $NO_OUTPUT;

    // private static final boolean DEBUG = true;

    // simplistic pruning: we prune node (and all following
    // nodes) if less than this number of terms go through it:
    /**
     * @var int
     */
    private $minSuffixCount1;

    // better pruning: we prune node (and all following
    // nodes) if the prior node has less than this number of
    // terms go through it:
    /**
     * @var int
     */
    private $minSuffixCount2;

    /** @var boolean */
    private $doShareNonSingletonNodes;
    /** @var int */
    private $shareMaxTailLength;

    /** @var int[] */
    private $lastInput = [];

    // NOTE: cutting this over to ArrayList instead loses ~6%
    // in build performance on 9.8M Wikipedia terms; so we
    // left this as an array:
    // current "frontier"
    /** @var UnCompiledNode[] */
    private $frontier;

    // Used for the BIT_TARGET_NEXT optimization (whereby
    // instead of storing the address of the target node for
    // a given arc, we mark a single bit noting that the next
    // node in the byte[] is the target node):

    public $lastFrozenNode;

    // Reused temporarily while building the FST:
    public $reusedBytesPerArc = [];//new int[4];

    /** @var int */
    public $nodeCount;
    /** @var int */
    public $arcCount;

    /** @var boolean */
    public $allowArrayArcs;

    /** @var BytesStore */
    public $bytes;

    /**
     * Instantiates an FST/FSA builder without any pruning. A shortcut
     * to {@link #Builder(FST.INPUT_TYPE, int, int, boolean,
     * boolean, int, Outputs, boolean, int)} with pruning options turned off.
     *
     * @return Builder
     */
    public static function newBuilder($inputType, Outputs $outputs)
    {
        return new self($inputType, 0, 0, true, true, PHP_INT_MAX, $outputs, true, 15);
    }

    /**
     * Instantiates an FST/FSA builder with all the possible tuning and construction
     * tweaks. Read parameter documentation carefully.
     *
     * @param $inputType
     *    The input type (transition labels). Can be anything from {@link INPUT_TYPE}
     *    enumeration. Shorter types will consume less memory. Strings (character sequences) are
     *    represented as {@link INPUT_TYPE#BYTE4} (full unicode codepoints).
     *
     * @param $minSuffixCount1
     *    If pruning the input graph during construction, this threshold is used for telling
     *    if a node is kept or pruned. If transition_count(node) &gt;= minSuffixCount1, the node
     *    is kept.
     *
     * @param $minSuffixCount2
     *    (Note: only Mike McCandless knows what this one is really doing...)
     *
     * @param $doShareSuffix
     *    If <code>true</code>, the shared suffixes will be compacted into unique paths.
     *    This requires an additional RAM-intensive hash map for lookups in memory. Setting this parameter to
     *    <code>false</code> creates a single suffix path for all input sequences. This will result in a larger
     *    FST, but requires substantially less memory and CPU during building.
     *
     * @param $doShareNonSingletonNodes
     *    Only used if doShareSuffix is true.  Set this to
     *    true to ensure FST is fully minimal, at cost of more
     *    CPU and more RAM during building.
     *
     * @param $shareMaxTailLength
     *    Only used if doShareSuffix is true.  Set this to
     *    Integer.MAX_VALUE to ensure FST is fully minimal, at cost of more
     *    CPU and more RAM during building.
     *
     * @param $outputs
     *    The output type for each input sequence. Applies only if building an FST. For
     *    FSA, use {@link NoOutputs#getSingleton()} and {@link NoOutputs#getNoOutput()} as the
     *    singleton output object.
     *
     * @param $allowArrayArcs
     *    Pass false to disable the array arc optimization
     *    while building the FST; this will make the resulting
     *    FST smaller but slower to traverse.
     *
     * @param $bytesPageBits
     *    How many bits wide to make each
     *    byte[] block in the BytesStore; if you know the FST
     *    will be large then make this larger.  For example 15
     *    bits = 32768 byte pages.
     *
     * @throws IllegalStateException
     */
    public function __construct($inputType, $minSuffixCount1, $minSuffixCount2, $doShareSuffix,
                                $doShareNonSingletonNodes, $shareMaxTailLength, Outputs $outputs,
                                $allowArrayArcs, $bytesPageBits)
    {
        $this->minSuffixCount1 = $minSuffixCount1;
        $this->minSuffixCount2 = $minSuffixCount2;
        $this->doShareNonSingletonNodes = $doShareNonSingletonNodes;
        $this->shareMaxTailLength = $shareMaxTailLength;
        $this->allowArrayArcs = $allowArrayArcs;
        $this->fst = (new FST(null, $outputs, null))->FST2($inputType, $outputs, $bytesPageBits);

        $this->bytes = &$this->fst->bytes;
//    assert bytes != null;
        if ($this->bytes === null) {
            throw new \AssertionError('NOT: bytes != null');
        }
        if ($doShareSuffix) {
            $this->dedupHash = new NodeHash($this->fst, $this->bytes->getReverseReader(false));
        } else {
            $this->dedupHash = null;
        }
        $this->NO_OUTPUT = $outputs->getNoOutput();

        for ($idx = 0; $idx < 10; $idx++) {
            $this->frontier[$idx] = new UnCompiledNode($this, $idx);
        }
    }

    public function getTermCount()
    {
        return $this->frontier[0]->inputCount;
    }

    public function getNodeCount()
    {
        // 1+ in order to count the -1 implicit final node
        return 1 + $this->nodeCount;
    }

    public function getArcCount()
    {
        return $this->arcCount;
    }

    public function getMappedStateCount()
    {
        return $this->dedupHash == null ? 0 : $this->nodeCount;
    }

    private function compileNode(UnCompiledNode $nodeIn, $tailLength)
    {
        $bytesPosStart = $this->bytes->getPosition();
        if ($this->dedupHash != null && ($this->doShareNonSingletonNodes || $nodeIn->numArcs <= 1) && $tailLength <= $this->shareMaxTailLength) {
            if ($nodeIn->numArcs == 0) {
                $node = $this->fst->addNode($this, $nodeIn);
                $this->lastFrozenNode = $node;
            } else {
                $node = $this->dedupHash->add($this, $nodeIn);
                error_log('DEDUP DONE');
            }
        } else {
            $node = $this->fst->addNode($this, $nodeIn);
        }
//    assert node != -2;
        if ($node == -2) {
            throw new \AssertionError('NOT: node != -2');
        }

        $bytesPosEnd = $this->bytes->nextWrite;
        if ($bytesPosEnd != $bytesPosStart) {
            // The FST added a new node:
//        assert bytesPosEnd > bytesPosStart;
            if ($bytesPosEnd <= $bytesPosStart) {
                throw new \AssertionError('NOT: bytesPosEnd > bytesPosStart');
            }
            $this->lastFrozenNode = $node;
        }

        $nodeIn->clear();

        $fn = new CompiledNode();
        $fn->node = $node;
        return $fn;
    }

    private function freezeTail($prefixLenPlus1)
    {
        //System.out.println("  compileTail " + prefixLenPlus1);
        $downTo = max(1, $prefixLenPlus1);
        for ($idx = count($this->lastInput); $idx >= $downTo; $idx--) {

            $doPrune = false;
            $doCompile = false;

            $node = $this->frontier[$idx];
            $parent = $this->frontier[$idx - 1];

            if ($node->inputCount < $this->minSuffixCount1) {
                $doPrune = true;
                $doCompile = true;
            } else {
                if ($idx > $prefixLenPlus1) {
                    // prune if parent's inputCount is less than suffixMinCount2
                    if ($parent->inputCount < $this->minSuffixCount2 || ($this->minSuffixCount2 == 1 && $parent->inputCount == 1 && $idx > 1)) {
                        // my parent, about to be compiled, doesn't make the cut, so
                        // I'm definitely pruned

                        // if minSuffixCount2 is 1, we keep only up
                        // until the 'distinguished edge', ie we keep only the
                        // 'divergent' part of the FST. if my parent, about to be
                        // compiled, has inputCount 1 then we are already past the
                        // distinguished edge.  NOTE: this only works if
                        // the FST outputs are not "compressible" (simple
                        // ords ARE compressible).
                        $doPrune = true;
                    } else {
                        // my parent, about to be compiled, does make the cut, so
                        // I'm definitely not pruned
                        $doPrune = false;
                    }
                    $doCompile = true;
                } else {
                    // if pruning is disabled (count is 0) we can always
                    // compile current node
                    $doCompile = $this->minSuffixCount2 == 0;
                }
            }

            //System.out.println("    label=" + ((char) lastInput.ints[lastInput.offset+idx-1]) + " idx=" + idx + " inputCount=" + frontier[idx].inputCount + " doCompile=" + doCompile + " doPrune=" + doPrune);

            if ($node->inputCount < $this->minSuffixCount2 || ($this->minSuffixCount2 == 1 && $node->inputCount == 1 && $idx > 1)) {
                // drop all arcs
                for ($arcIdx = 0; $arcIdx < $node->numArcs; $arcIdx++) {
                    /** @var UnCompiledNode $target */
                    $target = $node->arcs[$arcIdx]->target;
                    $target->clear();
                }
                $node->numArcs = 0;
            }

            if ($doPrune) {
                // this node doesn't make it -- deref it
                $node->clear();
                $parent->deleteLast($this->lastInput[$idx - 1], $node);
            } else {

                if ($this->minSuffixCount2 != 0) {
                    $this->compileAllTargets($node, count($this->lastInput) - $idx);
                }
                $nextFinalOutput = $node->output;

                // We "fake" the node as being final if it has no
                // outgoing arcs; in theory we could leave it
                // as non-final (the FST can represent this), but
                // FSTEnum, Util, etc., have trouble w/ non-final
                // dead-end states:
                $isFinal = $node->isFinal || $node->numArcs == 0;

                if ($doCompile) {
                    // this node makes it and we now compile it.  first,
                    // compile any targets that were previously
                    // undecided:
                    $parent->replaceLast($this->lastInput[$idx - 1],
                        $this->compileNode($node, 1 + count($this->lastInput) - $idx),
                        $nextFinalOutput,
                        $isFinal);
                } else {
                    // replaceLast just to install
                    // nextFinalOutput/isFinal onto the arc
                    $parent->replaceLast($this->lastInput[$idx - 1],
                        $node,
                        $nextFinalOutput,
                        $isFinal);
                    // this node will stay in play for now, since we are
                    // undecided on whether to prune it.  later, it
                    // will be either compiled or pruned, so we must
                    // allocate a new node:
                    $this->frontier[$idx] = new UnCompiledNode($this, $idx);
                }
            }
        }
    }

    /** Add the next input/output pair.  The provided input
     *  must be sorted after the previous one according to
     *  {@link IntsRef#compareTo}.  It's also OK to add the same
     *  input twice in a row with different outputs, as long
     *  as {@link Outputs} implements the {@link Outputs#merge}
     *  method. Note that input is fully consumed after this
     *  method is returned (so caller is free to reuse), but
     *  output is not.  So if your outputs are changeable (eg
     *  {@link ByteSequenceOutputs} or {@link
     *  IntSequenceOutputs}) then you cannot reuse across
     *  calls. */
    public function add($input, $output)
    {
        /*
        if (DEBUG) {
          BytesRef b = new BytesRef(input.length);
          for(int x=0;x<input.length;x++) {
            b.bytes[x] = (byte) input.ints[x];
          }
          b.length = input.length;
          if (output == NO_OUTPUT) {
            System.out.println("\nFST ADD: input=" + toString(b) + " " + b);
          } else {
            System.out.println("\nFST ADD: input=" + toString(b) + " " + b + " output=" + fst.outputs.outputToString(output));
          }
        }
        */

        // De-dup NO_OUTPUT since it must be a singleton:
        if ($output == $this->NO_OUTPUT) {
            $output = $this->NO_OUTPUT;
        }

//        assert lastInput.length() == 0 || input.compareTo(lastInput.get()) >= 0: "inputs are added out of order lastInput=" + lastInput.get() + " vs input=" + input;
        if (!(count($this->lastInput) == 0 || Util::array_cmp($input, $this->lastInput) >= 0)) {
            throw new \AssertionError("inputs are added out of order lastInput=" . implode(', ', $this->lastInput) . " vs input=" . implode(', ', $input));
        }
//    assert validOutput(output);
        if (!$this->validOutput($output)) {
            throw new \AssertionError('NOT: validOutput(output)');
        }

        //System.out.println("\nadd: " + input);
        if (count($input) == 0) {
            // empty input: only allowed as first input.  we have
            // to special case this because the packed FST
            // format cannot represent the empty input since
            // 'finalness' is stored on the incoming arc, not on
            // the node
            $this->frontier[0]->inputCount++;
            $this->frontier[0]->isFinal = true;
            $this->fst->setEmptyOutput($output);

            return;
        }

        // compare shared prefix length
        $pos1 = 0;
        $pos2 = 0;
        $pos1Stop = min(count($this->lastInput), count($input));
        while (true) {
            $this->frontier[$pos1]->inputCount++;
            error_log("  incr " . $pos1 . " ct=" . $this->frontier[$pos1]->inputCount/* . " n=" . $this->frontier[$pos1]*/);
            if ($pos1 >= $pos1Stop || $this->lastInput[$pos1] != $input[$pos2]) {
                break;
            }
            $pos1++;
            $pos2++;
        }
        $prefixLenPlus1 = $pos1 + 1;

        if (count($this->frontier) < count($input) + 1) {
//            $next = [];
            for ($idx = count($this->frontier); $idx < count($input) + 2; $idx++) {
                $this->frontier[$idx] = new UnCompiledNode($this, $idx);
            }
//            $this->frontier = $next;
        }

        // minimize/compile states from previous input's
        // orphan'd suffix
        $this->freezeTail($prefixLenPlus1);

        // init tail states for current input
        for ($idx = $prefixLenPlus1; $idx <= count($input); $idx++) {
            $this->frontier[$idx - 1]->addArc($input[/*$input->offset*/0 + $idx - 1], $this->frontier[$idx]);
            $this->frontier[$idx]->inputCount++;
        }

        $lastNode = $this->frontier[count($input)];
        if (count($this->lastInput) != count($input) || $prefixLenPlus1 != count($input) + 1) {
            $lastNode->isFinal = true;
            $lastNode->output = $this->NO_OUTPUT;
        }

        // push conflicting outputs forward, only as far as
        // needed
        for ($idx = 1; $idx < $prefixLenPlus1; $idx++) {
            $node = $this->frontier[$idx];
            $parentNode = $this->frontier[$idx - 1];

            $lastOutput = $parentNode->getLastOutput($input[$idx - 1]);
//      assert validOutput(lastOutput);
            if (!$this->validOutput($lastOutput)) {
                throw new \AssertionError('NOT: validOutput(lastOutput)');
            }

            if ($lastOutput != $this->NO_OUTPUT) {
                $commonOutputPrefix = $this->fst->outputs->common($output, $lastOutput);
//          assert validOutput(commonOutputPrefix);
                if (!$this->validOutput($commonOutputPrefix)) {
                    throw new \AssertionError('NOT: validOutput(commonOutputPrefix)');
                }
                $wordSuffix = $this->fst->outputs->subtract($lastOutput, $commonOutputPrefix);
//        assert validOutput(wordSuffix);
                if (!$this->validOutput($wordSuffix)) {
                    throw new \AssertionError('NOT: validOutput(wordSuffix)');
                }
                $parentNode->setLastOutput($input[$idx - 1], $commonOutputPrefix);
                $node->prependOutput($wordSuffix);
            } else {
                $commonOutputPrefix = $wordSuffix = $this->NO_OUTPUT;
            }

            $output = $this->fst->outputs->subtract($output, $commonOutputPrefix);
//      assert validOutput(output);
            if (!$this->validOutput($output)) {
                throw new \AssertionError('NOT: validOutput(output)');
            }
        }

        if (count($this->lastInput) == count($input) && $prefixLenPlus1 == 1 + count($input)) {
            // same input more than 1 time in a row, mapping to
            // multiple outputs
            $lastNode->output = $this->fst->outputs->merge($lastNode->output, $output);
        } else {
            // this new arc is private to this new input; set its
            // arc output to the leftover output:
            $this->frontier[$prefixLenPlus1 - 1]->setLastOutput($input[$prefixLenPlus1 - 1], $output);
        }

        // save last input
        $this->lastInput = $input;

        //System.out.println("  count[0]=" + frontier[0].inputCount);
    }

    public function validOutput($output)
    {
        return true; //output == NO_OUTPUT || !output.equals(NO_OUTPUT); //TODO: CHECK THAT!
    }

    /** Returns final FST.  NOTE: this will return null if
     *  nothing is accepted by the FST. */
    public function finish()
    {
        $root = $this->frontier[0];

        // minimize nodes in the last word's suffix
        $this->freezeTail(0);
        if ($root->inputCount < $this->minSuffixCount1 || $root->inputCount < $this->minSuffixCount2 || $root->numArcs == 0) {
            if ($this->fst->emptyOutput == null) {
                return null;
            } elseif ($this->minSuffixCount1 > 0 || $this->minSuffixCount2 > 0) {
                // empty string got pruned
                return null;
            }
        } else {
            if ($this->minSuffixCount2 != 0) {
                $this->compileAllTargets($root, count($this->lastInput));
            }
        }
        error_log('FINISH2');
        //if (DEBUG) System.out.println("  builder.finish root.isFinal=" + root.isFinal + " root.output=" + root.output);
        $this->fst->finish($this->compileNode($root, count($this->lastInput))->node);
        error_log('FINISH3');

        return $this->fst;
    }

    private function compileAllTargets(UnCompiledNode $node, $tailLength)
    {
        for ($arcIdx = 0; $arcIdx < $node->numArcs; $arcIdx++) {
            $arc = $node->arcs[$arcIdx];
            if (!$arc->target->isCompiled()) {
                // not yet compiled
                /** @var UnCompiledNode $n */
                $n = $arc->target;
                if ($n->numArcs == 0) {
                    //System.out.println("seg=" + segment + "        FORCE final arc=" + (char) arc.label);
                    $arc->isFinal = $n->isFinal = true;
                }
                $arc->target = $this->compileNode($n, $tailLength - 1);
            }
        }
    }


    public function fstRamBytesUsed()
    {
        return $this->fst->ramBytesUsed();
    }
}

/** Expert: holds a pending (seen but not yet serialized) arc. */
class BuilderArc
{
    public $label;                             // really an "unsigned" byte

    /** @var CompiledNode */
    public $target;
    public $isFinal;
    public $output;
    public $nextFinalOutput;
}

// NOTE: not many instances of Node or CompiledNode are in
// memory while the FST is being built; it's only the
// current "frontier":

interface Node
{
    public function isCompiled();
}

class CompiledNode implements Node
{
    public $node;

    public function isCompiled()
    {
        return true;
    }
}

/** Expert: holds a pending (seen but not yet serialized) Node. */
class UnCompiledNode implements Node
{

    /** @var Builder */
    public $owner;
    public $numArcs = 0;
    /** @var BuilderArc[] */
    public $arcs = [];
    // TODO: instead of recording isFinal/output on the
    // node, maybe we should use -1 arc to mean "end" (like
    // we do when reading the FST).  Would simplify much
    // code here...
    public $output;
    public $isFinal;
    public $inputCount;

    /** This node's depth, starting from the automaton root. */
    public $depth;

    /**
     * @param Builder $owner
     * @param         $depth
     *          The node's depth starting from the automaton root. Needed for
     *          LUCENE-2934 (node expansion based on conditions other than the
     *          fanout size).
     */
    public function __construct(Builder $owner, $depth)
    {
        $this->owner = $owner;
        $this->arcs[] = new Arc();
        $this->output = $this->owner->NO_OUTPUT;
        $this->depth = $depth;
    }

    public function isCompiled()
    {
        return false;
    }

    public function clear()
    {
        $this->numArcs = 0;
        $this->isFinal = false;
        $this->output = $this->owner->NO_OUTPUT;
        $this->inputCount = 0;

        // We don't clear the depth here because it never changes
        // for nodes on the frontier (even when reused).
    }

    public function getLastOutput($labelToMatch)
    {
//            assert numArcs > 0;
        if ($this->numArcs < 0) {
            throw new \AssertionError('NOT: numArcs > 0');
        }

//      assert arcs[numArcs-1].label == labelToMatch;
        if ($this->arcs[$this->numArcs - 1]->label != $labelToMatch) {
            throw new \AssertionError('NOT: arcs[numArcs-1].label == labelToMatch');
        }
        return $this->arcs[$this->numArcs - 1]->output;
    }

    public function addArc($label, Node $target)
    {
//            assert label >= 0;
        if ($label < 0) {
            throw new \AssertionError('NOT: label >= 0');
        }

//      assert numArcs == 0 || label > arcs[numArcs-1].label: "arc[-1].label=" + arcs[numArcs-1].label + " new label=" + label + " numArcs=" + numArcs;
        if (!($this->numArcs == 0 || $label > $this->arcs[$this->numArcs - 1]->label)) {
            throw new \AssertionError("arc[-1].label=" . $this->arcs[$this->numArcs - 1]->label . " new label=" . $label . " numArcs=" . $this->numArcs);
        }

        if ($this->numArcs == count($this->arcs)) {
//            $newArcs = []; //ArrayUtil.grow(arcs, numArcs+1);
//            for ($arcIdx = $this->numArcs; $arcIdx < $this->numArcs + 1; $arcIdx++) {
//                $newArcs[$arcIdx] = new Arc();
//            }
//
//            $this->arcs = $newArcs;
            $this->arcs[$this->numArcs] = new Arc();
        }

        $arc = $this->arcs[$this->numArcs++];
        $arc->label = $label;
        $arc->target = $target;
        $arc->output = $arc->nextFinalOutput = $this->owner->NO_OUTPUT;
        $arc->isFinal = false;
    }

    public function replaceLast($labelToMatch, Node $target, $nextFinalOutput, $isFinal)
    {
//            assert numArcs > 0;
        if ($this->numArcs <= 0) {
            throw new \AssertionError('NOT: numArcs > 0');
        }

        $arc = $this->arcs[$this->numArcs - 1];
//      assert arc.label == labelToMatch: "arc.label=" + arc.label + " vs " + labelToMatch;
        if ($arc->label != $labelToMatch) {
            throw new \AssertionError("arc.label=" . $arc->label . " vs " . $labelToMatch);
        }

        $arc->target = $target;
        //assert target.node != -2;
        $arc->nextFinalOutput = $nextFinalOutput;
        $arc->isFinal = $isFinal;
    }

    public function deleteLast($label, Node $target)
    {
//            assert numArcs > 0;
        if ($this->numArcs <= 0) {
            throw new \AssertionError('NOT: numArcs > 0');
        }
//      assert label == arcs[numArcs-1].label;
        if ($label != $this->arcs[$this->numArcs - 1]->label) {
            throw new \AssertionError('NOT: label == arcs[numArcs-1].label');
        }
//      assert target == arcs[numArcs-1].target;
        if ($target !== $this->arcs[$this->numArcs - 1]->target) {
            throw new \AssertionError('NOT: target == arcs[numArcs-1].target');
        }

        $this->numArcs--;
    }

    public function setLastOutput($labelToMatch, $newOutput)
    {
//            assert owner.validOutput(newOutput);
        if (!$this->owner->validOutput($newOutput)) {
            throw new \AssertionError('NOT: owner.validOutput(newOutput)');
        }
//            assert numArcs > 0;
        if ($this->numArcs <= 0) {
            throw new \AssertionError('NOT: numArcs > 0');
        }

        $arc = $this->arcs[$this->numArcs - 1];
//      assert arc.label == labelToMatch;
        if ($arc->label != $labelToMatch) {
            throw new \AssertionError('NOT: arc.label == labelToMatch');
        }
        $arc->output = $newOutput;
    }

    // pushes an output prefix forward onto all arcs
    public function prependOutput($outputPrefix)
    {
//            assert owner.validOutput(outputPrefix);
        if (!$this->owner->validOutput($outputPrefix)) {
            throw new \AssertionError('NOT: owner.validOutput(outputPrefix)');
        }

        for ($arcIdx = 0; $arcIdx < $this->numArcs; $arcIdx++) {
            $this->arcs[$arcIdx]->output = $this->owner->fst->outputs->add($outputPrefix, $this->arcs[$arcIdx]->output);
//                assert owner.validOutput(arcs[arcIdx].output);
            if (!$this->owner->validOutput($this->arcs[$arcIdx]->output)) {
                throw new \AssertionError('NOT: owner.validOutput(arcs[arcIdx].output)');
            }
        }

        if ($this->isFinal) {
            $this->output = $this->owner->fst->outputs->add($outputPrefix, $this->output);
//          assert owner.validOutput(output);
            if (!$this->owner->validOutput($this->output)) {
                throw new \AssertionError('NOT: owner.validOutput(output)');
            }
        }
    }
}