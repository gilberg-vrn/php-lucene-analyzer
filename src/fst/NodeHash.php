<?php

namespace ftIndex\fst;

use ftIndex\analyses\hunspell\Arc;
use ftIndex\analyses\hunspell\Builder;
use ftIndex\analyses\hunspell\BytesReader;
use ftIndex\analyses\hunspell\fst;
use ftIndex\analyses\hunspell\UnCompiledNode;

class NodeHash
{

    private $table;
    private $count;
    private $mask;
    private $fst;
    private $scratchArc;
    private $in;

    public function __construct(fst $fst, BytesReader $in)
    {
        $this->table = [];//new PagedGrowableWriter(16, 1 << 27, 8, PackedInts::COMPACT);
        $this->mask = 15;
        $this->fst = $fst;
        $this->in = $in;
        $this->scratchArc = new Arc();
    }

    private function nodesEqual(UnCompiledNode $node, $address)
    {
        $this->fst->readFirstRealTargetArc($address, $this->scratchArc, $this->in);
        if ($this->scratchArc->bytesPerArc != 0 && $node->numArcs != $this->scratchArc->numArcs) {
            return false;
        }
        for ($arcUpto = 0; $arcUpto < $node->numArcs; $arcUpto++) {
            $arc = $node->arcs[$arcUpto];
            if ($arc->label != $this->scratchArc->label ||
                !$arc->output->equals($this->scratchArc->output) ||
                $arc->target->node != $this->scratchArc->target ||
                !$arc->nextFinalOutput->equals($this->scratchArc->nextFinalOutput) ||
                $arc->isFinal != $this->scratchArc->isFinal()) {
                return false;
            }

            if ($this->scratchArc->isLast()) {
                if ($arcUpto == $node->numArcs - 1) {
                    return true;
                } else {
                    return false;
                }
            }
            $this->fst->readNextRealArc($this->scratchArc, $this->in);
        }

        return false;
    }

    // hash code for an unfrozen node.  This must be identical
    // to the frozen case (below)!!
    /**
     * @param UnCompiledNode|int $node
     *
     * @return int
     */
    private function hash($node)
    {
        if (is_numeric($node)) {
            return $this->hashFrozen($node);
        }
        $PRIME = 31;
        error_log("hash unfrozen");
        $h = 0;
        // TODO: maybe if number of arcs is high we can safely subsample?
        for ($arcIdx = 0; $arcIdx < $node->numArcs; $arcIdx++) {
            $arc = $node->arcs[$arcIdx];
            error_log("  label=" . $arc->label . " target=" . $arc->target->node . " h=" . $h . " output=" . $this->fst->outputs->outputToString($arc->output) . " isFinal?=" . $arc->isFinal);
            $h = $PRIME * $h + $arc->label;
            $n = $arc->target->node;
            $h = $PRIME * $h + (int)($n ^ ($n >> 32));
            $h = $PRIME * $h + crc32(implode('', $arc->output));
            $h = $PRIME * $h + crc32(implode('', $arc->nextFinalOutput));
            if ($arc->isFinal) {
                $h += 17;
            }
            $h = $h & PHP_INT_MAX;
        }
        error_log("  ret " . ($h/*&PHP_INT_MAX*/));
        return $h & PHP_INT_MAX;
    }

    // hash code for a frozen node
    private function hashFrozen($node)
    {
        $PRIME = 31;
        error_log("hash frozen node=" . $node);
        $h = 0;
        $this->fst->readFirstRealTargetArc($node, $this->scratchArc, $this->in);
        $c = 100;
        while (true && --$c > 0) {
            error_log("  label=" . $this->scratchArc->label . " target=" . $this->scratchArc->target . " h=" . $h . " output=" . $this->fst->outputs->outputToString($this->scratchArc->output) . " next?=" . ($this->scratchArc->flag(4) ? 'Y' : 'N') . " final?=" . ($this->scratchArc->isFinal() ? 'Y' : 'N') . " pos=" . $this->in->getPosition());
            $h = $PRIME * $h + $this->scratchArc->label;
            $h = $PRIME * $h + (int)($this->scratchArc->target ^ ($this->scratchArc->target >> 32));
            $h = $PRIME * $h + crc32(implode('', $this->scratchArc->output));
            $h = $PRIME * $h + crc32(implode('', $this->scratchArc->nextFinalOutput));
            if ($this->scratchArc->isFinal()) {
                $h += 17;
            }
            $h = $h & PHP_INT_MAX;
            if ($this->scratchArc->isLast()) {
                break;
            }
            $this->fst->readNextRealArc($this->scratchArc, $this->in);
        }
        //System.out.println("  ret " + (h&Integer.MAX_VALUE));
        return $h & PHP_INT_MAX;
    }

    public function add(Builder $builder, UnCompiledNode $nodeIn)
    {
        error_log("hash: add count=" . $this->count . " vs " . count($this->table) . " mask=" . $this->mask);
        $h = $this->hash($nodeIn);
        $pos = $h & $this->mask;
        $c = 0;
        while (true) {
            error_log('COUNTER: ' . $c);
            $v = isset($this->table[$pos]) ? $this->table[$pos] : 0;
            if ($v == 0) {
                // freeze & add
                error_log('ADD NODE');
                $node = $this->fst->addNode($builder, $nodeIn);
                error_log('ADD NODE DONE');
                //System.out.println("  now freeze node=" + node);
//        assert hash(node) == h : "frozenHash=" + hash(node) + " vs h=" + h;
                error_log('HASH');
                if ($this->hash($node) != $h) {
//                    throw new \AssertionError("frozenHash=" . $this->hash($node) . " vs h=" . $h);
                }
                error_log('HASH DONE');
                $this->count++;
                $this->table[$pos] = $node;
                // Rehash at 2/3 occupancy:
                error_log('NEED REHASH?');
                if ($this->count > 2 * count($this->table) / 3) {
                    error_log('REHASH');
                    $this->rehash();
                }
                error_log('REHASH DONE');
                return $node;
            } else {
                if ($this->nodesEqual($nodeIn, $v)) {
                    // same node is already here
                    return $v;
                }
            }

            // quadratic probe
            $pos = ($pos + (++$c)) & $this->mask;
        }
    }

    // called only by rehash
    private function addNew($address)
    {
        $pos = $this->hash($address) & $this->mask;
        $c = 0;
        while (true) {
            if (!isset($this->table[$pos]) || $this->table[$pos] == 0) {
                $this->table[$pos] = $address;
                break;
            }

            // quadratic probe
            $pos = ($pos + (++$c)) & $this->mask;
        }
    }

    private function rehash()
    {
        $oldTable = $this->table;

        $this->table = [];//new PagedGrowableWriter(2 * $oldTable->size(), 1 << 30, PackedInts::bitsRequired($this->count), PackedInts::COMPACT);
        $this->mask = 2 * count($oldTable) - 1;
        for ($idx = 0; $idx < count($oldTable); $idx++) {
            $address = isset($oldTable[$idx]) ? $oldTable[$idx] : 0;
            if ($address != 0) {
                $this->addNew($address);
            }
        }
    }
}
