<?php

namespace ftIndex\analyses\hunspell;
/** Represents a single arc. */
class Arc
{
    /**
     * @var int
     */
    public $label;

    /**
     * var mixed
     */
    public $output;

    /**
     * To node (ord or address)
     *
     * @var int
     */
    public $target;

    /**
     * @var int (byte)
     */
    public $flags;

    /**
     * @var mixed
     */
    public $nextFinalOutput;

    // address (into the byte[]), or ord/address if label == END_LABEL
    /**
     * @var int
     */
    public $nextArc;

    /** Where the first arc in the array starts; only valid if
     *  bytesPerArc != 0 */
    /**
     * @var int
     */
    public $posArcsStart;

    /** Non-zero if this arc is part of an array, which means all
     *  arcs for the node are encoded with a fixed number of bytes so
     *  that we can random access by index.  We do when there are enough
     *  arcs leaving one node.  It wastes some bytes but gives faster
     *  lookups. */
    /**
     * @var int
     */
    public $bytesPerArc;

    /** Where we are in the array; only valid if bytesPerArc != 0. */
    /**
     * @var int
     */
    public $arcIdx;

    /** How many arcs in the array; only valid if bytesPerArc != 0. */
    /**
     * @var int
     */
    public $numArcs;

    /** Returns this */
    public function copyFrom(Arc $other)
    {
        $this->label = $other->label;
        $this->target = $other->target;
        $this->flags = $other->flags;
        $this->output = $other->output;
        $this->nextFinalOutput = $other->nextFinalOutput;
        $this->nextArc = $other->nextArc;
        $this->bytesPerArc = $other->bytesPerArc;
        if ($this->bytesPerArc != 0) {
            $this->posArcsStart = $other->posArcsStart;
            $this->arcIdx = $other->arcIdx;
            $this->numArcs = $other->numArcs;
        }

        return $this;
    }

    public function flag($flag)
    {
        return FST::flag($this->flags, $flag);
    }

    public function isLast()
    {
        return $this->flag(FST::BIT_LAST_ARC);
    }

    public function isFinal()
    {
        return $this->flag(FST::BIT_FINAL_ARC);
    }

    public function __toString()
    {
        $b = '';
        $b .= " target={$this->target}";
        $b .= " label=0x" . sprintf('%2x', $this->label);

        if ($this->flag(FST::BIT_FINAL_ARC)) {
            $b .= " final";
        }
        if ($this->flag(FST::BIT_LAST_ARC)) {
            $b .= " last";
        }
        if ($this->flag(FST::BIT_TARGET_NEXT)) {
            $b .= " targetNext";
        }
        if ($this->flag(FST::BIT_STOP_NODE)) {
            $b .= " stop";
        }
        if ($this->flag(FST::BIT_ARC_HAS_OUTPUT)) {
            $b .= " output={$this->output}";
        }
        if ($this->flag(FST::BIT_ARC_HAS_FINAL_OUTPUT)) {
            $b .= " nextFinalOutput={$this->nextFinalOutput}";
        }
        if ($this->bytesPerArc != 0) {
            $b .= " arcArray(idx={$this->arcIdx} of {$this->numArcs})";
        }
        return $b;
    }
}