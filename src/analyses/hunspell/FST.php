<?php

namespace ftIndex\analyses\hunspell;

use ftIndex\fst\BytesStore;
use ftIndex\fst\FSTStore;
use ftIndex\fst\OnHeapFSTStore;
use ftIndex\store\ByteArrayDataOutput;
use ftIndex\store\ByteBuffersDataOutput;
use ftIndex\store\DataInput;
use ftIndex\store\DataOutput;
use ftIndex\fst\Outputs;

class FST
{


//private static final long BASE_RAM_BYTES_USED = RamUsageEstimator.shallowSizeOfInstance(FST.class);
//private static final long ARC_SHALLOW_RAM_BYTES_USED = RamUsageEstimator.shallowSizeOfInstance(Arc.class);
    const BASE_RAM_BYTES_USED        = 1;
    const ARC_SHALLOW_RAM_BYTES_USED = 1;

    /** Specifies allowed range of each int input label for
     *  this FST. */
    const INPUT_TYPE_BYTE1 = 0;
    const INPUT_TYPE_BYTE2 = 1;
    const INPUT_TYPE_BYTE4 = 2;

    const BIT_FINAL_ARC   = 1 << 0;
    const BIT_LAST_ARC    = 1 << 1;
    const BIT_TARGET_NEXT = 1 << 2;

    // TODO: we can free up a bit if we can nuke this:
    const BIT_STOP_NODE = 1 << 3;

    /** This flag is set if the arc has an output. */
    const BIT_ARC_HAS_OUTPUT = 1 << 4;

    const BIT_ARC_HAS_FINAL_OUTPUT = 1 << 5;

    // We use this as a marker (because this one flag is
    // illegal by itself ...):
    const ARCS_AS_FIXED_ARRAY = self::BIT_ARC_HAS_FINAL_OUTPUT;

    /**
     * @see #shouldExpand(Builder, Builder.UnCompiledNode)
     */
    const FIXED_ARRAY_SHALLOW_DISTANCE = 3; // 0 => only root node.

    /**
     * @see #shouldExpand(Builder, Builder.UnCompiledNode)
     */
    const FIXED_ARRAY_NUM_ARCS_SHALLOW = 5;

    /**
     * @see #shouldExpand(Builder, Builder.UnCompiledNode)
     */
    const FIXED_ARRAY_NUM_ARCS_DEEP = 10;

    // Increment version to change it
    const FILE_FORMAT_NAME = "FST";
    const VERSION_START    = 6;
    const VERSION_CURRENT  = self::VERSION_START;

    // Never serialized; just used to represent the virtual
    // final node w/ no arcs:
    const FINAL_END_NODE = -1;

    // Never serialized; just used to represent the virtual
    // non-final node w/ no arcs:
    const NON_FINAL_END_NODE = 0;

    /** If arc has this label then that arc is final/accepted */
    const END_LABEL = -1;

    public $inputType;

    // if non-null, this FST accepts the empty string and
    // produces this output
    public $emptyOutput;

    /** A {@link BytesStore}, used during building, or during reading when
     *  the FST is very large (more than 1 GB).  If the FST is less than 1
     *  GB then bytesArray is set instead.
     * @var BytesStore
     */
    public $bytes;

    /**
     * @var FSTStore
     */
    private $fstStore;

    private $startNode = -1;

    /**
     * @var Outputs
     */
    public $outputs;

    /**
     * @var Arc[]
     */
    private $cachedRootArcs = [];

    public static function flag($flags, $bit)
    {
        return ($flags & $bit) != 0;
    }

    /**
     * @var int
     */
    private $version;

    // make a new empty FST, for building; Builder invokes
    // this ctor
    /**
     * fst constructor.
     *
     * @param int     $inputType
     * @param Outputs $outputs
     * @param int     $bytesPageBits
     */
    public function FST2($inputType, Outputs $outputs, $bytesPageBits)
    {
        $this->inputType = $inputType;
        $this->outputs = $outputs;
        $this->version = self::VERSION_CURRENT;
        $this->fstStore = null;
        $this->bytes = BytesStore::constructByBlockBits($bytesPageBits); //new BytesStore($bytesPageBits);
        // pad: ensure no node gets address 0 which is reserved to mean
        // the stop state w/ no arcs
        $this->bytes->writeByte(0);

        $this->emptyOutput = null;

        return $this;
    }

    const DEFAULT_MAX_BLOCK_BITS = 30;

    /**
     * Load a previously saved FST.
     *
     * @param DataInput $in
     * @param Outputs   $outputs
     *
     * @return self
     */
    public static function newFST($in, $outputs)
    {
        return new self(null, $outputs, new OnHeapFSTStore(self::DEFAULT_MAX_BLOCK_BITS));
    }

    /** Load a previously saved FST; maxBlockBits allows you to
     *  control the size of the byte[] pages used to hold the FST bytes.
     *
     * @param DataInput $in
     * @param Outputs    $outputs
     * @param FSTStore   $fstStore
     *
     * @throws IllegalStateException
     */
    public function __construct($in = null, $outputs, $fstStore)
    {
        $this->bytes = null;
        if ($fstStore === null) {
            $fstStore = new OnHeapFSTStore(self::DEFAULT_MAX_BLOCK_BITS);
        }
        $this->fstStore = $fstStore;
        $this->outputs = $outputs;

        // NOTE: only reads most recent format; we don't have
        // back-compat promise for FSTs (they are experimental):
        $this->version = self::VERSION_CURRENT;
//    $this->version = CodecUtil.checkHeader($in, self::FILE_FORMAT_NAME, self::VERSION_START, self::VERSION_CURRENT);
        if ($in !== null) {
            if ($in->readByte() == 1) {
                // accepts empty string
                // 1 KB blocks:
                $emptyBytes = BytesStore::constructByBlockBits(10);
                $numBytes = $in->readVInt();
                $emptyBytes->copyBytes($in, $numBytes);

                // De-serialize empty-string output:
                $reader = $emptyBytes->getReverseReader();
                // NoOutputs uses 0 bytes when writing its output,
                // so we have to check here else BytesStore gets
                // angry:
                if ($numBytes > 0) {
                    $reader->setPosition($numBytes - 1);
                }
                $this->emptyOutput = $this->outputs->readFinalOutput($reader);
            } else {
                $this->emptyOutput = null;
            }
            $t = $in->readByte();
            switch ($t) {
                case 0:
                    $this->inputType = self::INPUT_TYPE_BYTE1;
                    break;
                case 1:
                    $this->inputType = self::INPUT_TYPE_BYTE2;
                    break;
                case 2:
                    $this->inputType = self::INPUT_TYPE_BYTE4;
                    break;
                default:
                    throw new IllegalStateException("invalid input type " . $t);
            }
            $this->startNode = $in->readVLong();

            $numBytes = $in->readVLong();
            $this->fstStore->init($in, $numBytes);
        }
        $this->cacheRootArcs();
    }

    public function getInputType()
    {
        return $this->inputType;
    }

    /**
     * @param Arc[] $arcs
     *
     * @return mixed
     */
    public function ramBytesUsed($arcs = null)
    {
        $size = 0;
        if ($arcs != null) {

            $size += sizeof($arcs);
            foreach ($arcs as $arc) {
                if ($arc != null) {
                    $size += self::ARC_SHALLOW_RAM_BYTES_USED;
                    if ($arc->output != null && $arc->output != $this->outputs->getNoOutput()) {
                        $size += $this->outputs->ramBytesUsed($arc->output);
                    }
                    if ($arc->nextFinalOutput != null && $arc->nextFinalOutput != $this->outputs->getNoOutput()) {
                        $size += $this->outputs->ramBytesUsed($arc->nextFinalOutput);
                    }
                }
            }
        } else {
            return $this->ramBytesUsedOver();
        }

        return $size;
    }

    /**
     * @var int
     */
    private $cachedArcsBytesUsed;

    public function ramBytesUsedOver()
    {
        $size = self::BASE_RAM_BYTES_USED;
        if ($this->fstStore != null) {
            $size += $this->fstStore->ramBytesUsed();
        } else {
            $size += $this->bytes->ramBytesUsed();
        }

        $size += $this->cachedArcsBytesUsed;
        return $size;
    }

    public function __toString()
    {
        return __CLASS__ . "(input={$this->inputType},output={$this->outputs}";
    }

    public function finish($newStartNode)
    {
//    assert newStartNode <= bytes.getPosition();
        if ($newStartNode > $this->bytes->getPosition()) {
            throw new \AssertionError('Not: newStartNode <= bytes.getPosition()');
        }

        if ($this->startNode != -1) {
            throw new IllegalStateException("already finished");
        }

        if ($newStartNode == self::FINAL_END_NODE && $this->emptyOutput != null) {
            $newStartNode = 0;
        }
        $this->startNode = $newStartNode;
        $this->bytes->finish();
        $this->cacheRootArcs();
    }

    private function initArcs($count)
    {
        $arcs = [];
        for ($i = 0; $i < $count; $i++) {
            $arcs[] = new Arc();
        }

        return $arcs;
    }

    // Optionally caches first 128 labels
    private function cacheRootArcs()
    {
        // We should only be called once per FST:
//    assert cachedArcsBytesUsed == 0;
        if ($this->cachedArcsBytesUsed != 0) {
            throw new \AssertionError('Not: cachedArcsBytesUsed == 0');
        }

        $arc = new Arc();
        $this->getFirstArc($arc);
        if ($this->targetHasArcs($arc)) {
            $in = $this->getBytesReader();
            $arcs = $this->initArcs(0x80);
            $this->readFirstRealTargetArc($arc->target, $arc, $in);
            $count = 0;
            while (true) {
//          assert arc.label != END_LABEL;
                if ($arc->label == self::END_LABEL) {
                    throw new \AssertionError('NOT: arc.label != END_LABEL');
                }

                if ($arc->label < count($arcs)) {
                    $arcs[$arc->label] = clone $arc;
                } else {
                    break;
                }
                if ($arc->isLast()) {
                    break;
                }
                $this->readNextRealArc($arc, $in);
                $count++;
            }

            $cacheRAM = (int)$this->ramBytesUsed($arcs);

            // Don't cache if there are only a few arcs or if the cache would use > 20% RAM of the FST itself:
            if ($count >= self::FIXED_ARRAY_NUM_ARCS_SHALLOW && $cacheRAM < $this->ramBytesUsed() / 5) {
                $this->cachedRootArcs = $arcs;
                $this->cachedArcsBytesUsed = $cacheRAM;
            }
        }
    }

    public function getEmptyOutput()
    {
        return $this->emptyOutput;
    }

    public function setEmptyOutput($v)
    {
        if ($this->emptyOutput != null) {
            $this->emptyOutput = $this->outputs->merge($this->emptyOutput, $v);
        } else {
            $this->emptyOutput = $v;
        }
    }

    /**
     * @param DataOutput $out
     *
     * @throws IllegalStateException
     */
    public function save($out)
    {
        if ($this->startNode == -1) {
            throw new IllegalStateException("call finish first");
        }
        CodecUtil::writeHeader($out, self::FILE_FORMAT_NAME, self::VERSION_CURRENT);
        // TODO: really we should encode this as an arc, arriving
        // to the root node, instead of special casing here:
        if ($this->emptyOutput != null) {
            // Accepts empty string
            $out->writeByte(1);

            // Serialize empty-string output:
            $ros = new ByteBuffersDataOutput();
            $this->outputs->writeFinalOutput($this->emptyOutput, $ros);
            $emptyOutputBytes = $ros->toArrayCopy();

            // reverse
            $stopAt = count($emptyOutputBytes) / 2;
            $upto = 0;
            while ($upto < $stopAt) {
                $b = $emptyOutputBytes[$upto];
                $emptyOutputBytes[$upto] = $emptyOutputBytes[count($emptyOutputBytes) - $upto - 1];
                $emptyOutputBytes[count($emptyOutputBytes) - $upto - 1] = $b;
                $upto++;
            }
            $out->writeVInt(count(emptyOutputBytes));
            $out->writeBytes($emptyOutputBytes, 0, count(emptyOutputBytes));
        } else {
            $out->writeByte(0);
        }
        if ($this->inputType == self::INPUT_TYPE_BYTE1) {
            $t = 0;
        } else {
            if ($this->inputType == self::INPUT_TYPE_BYTE2) {
                $t = 1;
            } else {
                $t = 2;
            }
        }
        $out->writeByte($t);
        $out->writeVLong($this->startNode);
        if ($this->bytes != null) {
            $numBytes = $this->bytes->getPosition();
            $out->writeVLong($numBytes);
            $this->bytes->writeTo($out);
        } else {
//        assert fstStore != null;
            if ($this->fstStore == null) {
                throw new \AssertionError('Not: fstStore != null');
            }
            $this->fstStore->writeTo($out);
        }
    }

    /**
     * Writes an automaton to a file.
     */
    public function saveByPath($path)
    {
        $os = new BufferedOutputStream($path);
        $this->save(new OutputStreamDataOutput($os));
    }

    /**
     * Reads an automaton from a file.
     */
    public static function read($path, Outputs $outputs)
    {
        $is = new newInputStream(path);
        return self::newFST(new InputStreamDataInput(new BufferedInputStream($is)), $outputs);
    }

    private function writeLabel(BytesStore $out, $v)
    {
//    assert v >= 0: "v=" + v;
        if ($v < 0) {
            throw new \AssertionError("v=" . $v);
        }

        if ($this->inputType == self::INPUT_TYPE_BYTE1) {
//        assert v <= 255: "v=" + v;
            if ($v > 255) {
                throw new \AssertionError("v=" . $v);
            }
            $out->writeByte($v);
        } elseif ($this->inputType == self::INPUT_TYPE_BYTE2) {
//            assert v <= 65535: "v=" + v;
            if ($v > 65535) {
                throw new \AssertionError("v=" . $v);
            }
            $out->writeShort($v);
        } else {
            $out->writeVInt($v);
        }
    }

    /** Reads one BYTE1/2/4 label from the provided {@link DataInput}. */
    public function readLabel(DataInput $in)
    {
        if ($this->inputType == self::INPUT_TYPE_BYTE1) {
            // Unsigned byte:
            $v = $in->readByte() & 0xFF;
        } else {
            if ($this->inputType == self::INPUT_TYPE_BYTE2) {
                // Unsigned short:
                $v = $in->readShort() & 0xFFFF;
            } else {
                $v = $in->readVInt();
            }
        }
        return $v;
    }

    /** returns true if the node at this address has any
     *  outgoing arcs */
    public static function targetHasArcs(Arc $arc)
    {
        return $arc->target > 0;
    }

    // serializes new node by appending its bytes to the end
    // of the current byte[]
    public function addNode(Builder $builder, UnCompiledNode $nodeIn)
    {
        $NO_OUTPUT = $this->outputs->getNoOutput();

        //System.out.println("FST.addNode pos=" + bytes.getPosition() + " numArcs=" + nodeIn.numArcs);
        if ($nodeIn->numArcs == 0) {
            if ($nodeIn->isFinal) {
                return self::FINAL_END_NODE;
            } else {
                return self::NON_FINAL_END_NODE;
            }
        }

        $startAddress = $builder->bytes->getPosition();
        error_log("  startAddr=" . $startAddress);

        $doFixedArray = self::shouldExpand($builder, $nodeIn);
        if ($doFixedArray) {
            //System.out.println("  fixedArray");
            if (count($builder->reusedBytesPerArc) < $nodeIn->numArcs) {
                $builder->reusedBytesPerArc = []; //new int[ArrayUtil->oversize(nodeIn->numArcs, 1)];
            }
        }

        $builder->arcCount += $nodeIn->numArcs;

        $lastArc = $nodeIn->numArcs - 1;

        $lastArcStart = $builder->bytes->getPosition();
        $maxBytesPerArc = 0;
        for ($arcIdx = 0; $arcIdx < $nodeIn->numArcs; $arcIdx++) {
            $arc = $nodeIn->arcs[$arcIdx];
            $target = $arc->target; //(CompiledNode) $arc->target;
            $flags = 0;
//            error_log("  arc " . $arcIdx . " label=" . $arc->label . " -> target=" . $target->node);

            if ($arcIdx == $lastArc) {
                $flags |= self::BIT_LAST_ARC;
            }

            if ($builder->lastFrozenNode == $target->node && !$doFixedArray) {
                // TODO: for better perf (but more RAM used) we
                // could avoid this except when arc is "near" the
                // last arc:
                $flags += self::BIT_TARGET_NEXT;
            }

            if ($arc->isFinal) {
                $flags += self::BIT_FINAL_ARC;
                if ($arc->nextFinalOutput != $NO_OUTPUT) {
                    $flags += self::BIT_ARC_HAS_FINAL_OUTPUT;
                }
            } else {
//          assert arc.nextFinalOutput == NO_OUTPUT;
                if ($arc->nextFinalOutput != $NO_OUTPUT) {
                    throw new \AssertionError('NOT: arc.nextFinalOutput == NO_OUTPUT');
                }
            }

            $targetHasArcs = $target->node > 0;

            if (!$targetHasArcs) {
                $flags += self::BIT_STOP_NODE;
            }

            if ($arc->output != $NO_OUTPUT) {
                $flags += self::BIT_ARC_HAS_OUTPUT;
            }

            $builder->bytes->writeByte($flags);
            self::writeLabel($builder->bytes, $arc->label);

             error_log("  write arc: label=" . \IntlChar::chr($arc->label) . " flags=" . $flags . " target=" . $target->node . " pos=" . $this->bytes->getPosition() . " output=" . $this->outputs->outputToString($arc->output));

            if ($arc->output != $NO_OUTPUT) {
                $this->outputs->write($arc->output, $builder->bytes);
                //System.out.println("    write output");
            }

            if ($arc->nextFinalOutput != $NO_OUTPUT) {
                //System.out.println("    write final output");
                $this->outputs->writeFinalOutput($arc->nextFinalOutput, $builder->bytes);
            }

            if ($targetHasArcs && ($flags & self::BIT_TARGET_NEXT) == 0) {
//          assert target.node > 0;
                if ($target->node <= 0) {
                    throw new \AssertionError('NOT: target.node > 0');
                }
                //System.out.println("    write target");
                $builder->bytes->writeVLong($target->node);
            }

            // just write the arcs "like normal" on first pass,
            // but record how many bytes each one took, and max
            // byte size:
            if ($doFixedArray) {
                $builder->reusedBytesPerArc[$arcIdx] = (int)($builder->bytes->getPosition() - $lastArcStart);
                $lastArcStart = $builder->bytes->getPosition();
                $maxBytesPerArc = max($maxBytesPerArc, $builder->reusedBytesPerArc[$arcIdx]);
                error_log("    bytes=" . $builder->reusedBytesPerArc[$arcIdx]);
            }
        }

        // TODO: try to avoid wasteful cases: disable doFixedArray in that case
        /*
         *
         * LUCENE-4682: what is a fair heuristic here?
         * It could involve some of these:
         * 1. how "busy" the node is: nodeIn.inputCount relative to frontier[0].inputCount?
         * 2. how much binSearch saves over scan: nodeIn.numArcs
         * 3. waste: numBytes vs numBytesExpanded
         *
         * the one below just looks at #3 */
        if ($doFixedArray) {
          // rough heuristic: make this 1.25 "waste factor" a parameter to the phd ctor????
          $numBytes = $lastArcStart - $startAddress;
          $numBytesExpanded = $maxBytesPerArc * $nodeIn->numArcs;
          if ($numBytesExpanded > $numBytes*1.25) {
            $doFixedArray = false;
          }
        }
        // */

        if ($doFixedArray) {
            $MAX_HEADER_SIZE = 11; // header(byte) + numArcs(vint) + numBytes(vint)
//      assert maxBytesPerArc > 0;
            if ($maxBytesPerArc <= 0) {
                throw new \AssertionError('NOT: maxBytesPerArc > 0');
            }
            // 2nd pass just "expands" all arcs to take up a fixed
            // byte size

            //System.out.println("write int @pos=" + (fixedArrayStart-4) + " numArcs=" + nodeIn.numArcs);
            // create the header
            // TODO: clean this up: or just rewind+reuse and deal with it
            $header = []; //new byte[MAX_HEADER_SIZE];
            $bad = new ByteArrayDataOutput($header, 0, $MAX_HEADER_SIZE);
            // write a "false" first arc:
            $bad->writeByte(self::ARCS_AS_FIXED_ARRAY);
            $bad->writeVInt($nodeIn->numArcs);
            $bad->writeVInt($maxBytesPerArc);
            $headerLen = $bad->getPosition();

            $fixedArrayStart = $startAddress + $headerLen;

            // expand the arcs in place, backwards
            $srcPos = $builder->bytes->getPosition();
            $destPos = $fixedArrayStart + $nodeIn->numArcs * $maxBytesPerArc;
//      assert destPos >= srcPos;
            if ($destPos < $srcPos) {
                throw new \AssertionError('NOT: destPos >= srcPos');
            }
            if ($destPos > $srcPos) {
                $builder->bytes->skipBytes((int)($destPos - $srcPos));
                for ($arcIdx = $nodeIn->numArcs - 1; $arcIdx >= 0; $arcIdx--) {
                    $destPos -= $maxBytesPerArc;
                    $srcPos -= $builder->reusedBytesPerArc[$arcIdx];
                    //System.out.println("  repack arcIdx=" + arcIdx + " srcPos=" + srcPos + " destPos=" + destPos);
                    if ($srcPos != $destPos) {
                        //System.out.println("  copy len=" + builder.reusedBytesPerArc[arcIdx]);
//                  assert destPos > srcPos: "destPos=" + destPos + " srcPos=" + srcPos + " arcIdx=" + arcIdx + " maxBytesPerArc=" + maxBytesPerArc + " reusedBytesPerArc[arcIdx]=" + builder.reusedBytesPerArc[arcIdx] + " nodeIn.numArcs=" + nodeIn.numArcs;
                        if ($destPos <= $srcPos) {
                            throw new \AssertionError("destPos=" . $destPos . " srcPos=" . $srcPos . " arcIdx=" . $arcIdx . " maxBytesPerArc=" . $maxBytesPerArc . " reusedBytesPerArc[arcIdx]=" . $builder->reusedBytesPerArc[$arcIdx] . " nodeIn.numArcs=" . $nodeIn->numArcs);
                        }
                        $builder->bytes->copyBytes($srcPos, $destPos, $builder->reusedBytesPerArc[$arcIdx]);
                    }
                }
            }

            // now write the header
            $builder->bytes->writeBytesByDestination($startAddress, $header, 0, $headerLen);
        }

        $thisNodeAddress = $builder->bytes->getPosition() - 1;

        $builder->bytes->reverse($startAddress, $thisNodeAddress);

        $builder->nodeCount++;

        return $thisNodeAddress;
    }

    /** Fills virtual 'start' arc, ie, an empty incoming arc to
     *  the FST's start node */
    public function getFirstArc(Arc $arc)
    {
        $NO_OUTPUT = $this->outputs->getNoOutput();

        if ($this->emptyOutput != null) {
            $arc->flags = self::BIT_FINAL_ARC | self::BIT_LAST_ARC;
            $arc->nextFinalOutput = $this->emptyOutput;
            if ($this->emptyOutput != $NO_OUTPUT) {
                $arc->flags |= self::BIT_ARC_HAS_FINAL_OUTPUT;
            }
        } else {
            $arc->flags = self::BIT_LAST_ARC;
            $arc->nextFinalOutput = $NO_OUTPUT;
        }
        $arc->output = $NO_OUTPUT;

        // If there are no nodes, ie, the FST only accepts the
        // empty string, then startNode is 0
        $arc->target = $this->startNode;

        return $arc;
    }

    /** Follows the <code>follow</code> arc and reads the last
     *  arc of its target; this changes the provided
     *  <code>arc</code> (2nd arg) in-place and returns it.
     *
     * @return Arc Returns the second argument
     * (<code>arc</code>). */
    public function readLastTargetArc(Arc $follow, Arc $arc, BytesReader $in)
    {
        //System.out.println("readLast");
        if (!$this->targetHasArcs($follow)) {
            //System.out.println("  end node");
//        assert follow.isFinal();
            if (!$follow->isFinal()) {
                throw new \AssertionError('NOT follow.isFinal()');
            }

            $arc->label = self::END_LABEL;
            $arc->target = self::FINAL_END_NODE;
            $arc->output = $follow->nextFinalOutput;
            $arc->flags = self::BIT_LAST_ARC;

            return $arc;
        } else {
            $in->setPosition($follow->target);
            $b = $in->readByte();
            if ($b == self::ARCS_AS_FIXED_ARRAY) {
                // array: jump straight to end
                $arc->numArcs = $in->readVInt();
                $arc->bytesPerArc = $in->readVInt();
                //System.out.println("  array numArcs=" + $arc->numArcs + " bpa=" + $arc->bytesPerArc);
                $arc->posArcsStart = $in->getPosition();
                $arc->arcIdx = $arc->numArcs - 2;
            } else {
                $arc->flags = $b;
                // non-array: linear scan
                $arc->bytesPerArc = 0;
                //System.out.println("  scan");
                while (!$arc->isLast()) {
                    // skip this arc:
                    $this->readLabel($in);
                    if ($arc->flag(self::BIT_ARC_HAS_OUTPUT)) {
                        $this->outputs->skipOutput($in);
                    }
                    if ($arc->flag(self::BIT_ARC_HAS_FINAL_OUTPUT)) {
                        $this->outputs->skipFinalOutput($in);
                    }
                    if ($arc->flag(self::BIT_STOP_NODE)) {
                    } else {
                        if ($arc->flag(self::BIT_TARGET_NEXT)) {
                        } else {
                            $this->readUnpackedNodeTarget($in);
                        }
                    }
                    $arc->flags = $in->readByte();
                }
                // Undo the byte flags we read:
                $in->skipBytes(-1);
                $arc->nextArc = $in->getPosition();
            }
            $this->readNextRealArc($arc, $in);
//      assert $arc->isLast();
            if (!$arc->isLast()) {
                throw new \AssertionError('NOT: $arc->isLast()');
            }
            return $arc;
        }
    }

    private function readUnpackedNodeTarget(BytesReader $in)
    {
        return $in->readVLong();
    }

    /**
     * Follow the <code>follow</code> arc and read the first arc of its target;
     * this changes the provided <code>arc</code> (2nd arg) in-place and returns
     * it.
     *
     * @return Arc Returns the second argument (<code>arc</code>).
     */
    public function readFirstTargetArc(Arc $follow, Arc $arc, BytesReader $in)
    {
        //int pos = address;
        //System.out.println("    readFirstTarget follow.target=" + follow.target + " isFinal=" + follow.isFinal());
        if ($follow->isFinal()) {
            // Insert "fake" final first arc:
            $arc->label = self::END_LABEL;
            $arc->output = $follow->nextFinalOutput;
            $arc->flags = self::BIT_FINAL_ARC;
            if ($follow->target <= 0) {
                $arc->flags |= self::BIT_LAST_ARC;
            } else {
                // NOTE: nextArc is a node (not an address!) in this case:
                $arc->nextArc = $follow->target;
            }
            $arc->target = self::FINAL_END_NODE;
            //System.out.println("    insert isFinal; nextArc=" + follow.target + " isLast=" + $arc->isLast() + " output=" + outputs.outputToString($arc->output));
            return $arc;
        } else {
            return $this->readFirstRealTargetArc($follow->target, $arc, $in);
        }
    }

    public function readFirstRealTargetArc($node, Arc $arc, BytesReader $in)
    {
        $address = $node;
        $in->setPosition($address);
        //System.out.println("  readFirstRealTargtArc address="
        //+ address);
        //System.out.println("   flags=" + arc.flags);

        $byte = $in->readByte();
        if ($byte == self::ARCS_AS_FIXED_ARRAY) {
            //System.out.println("  fixedArray");
            // this is first arc in a fixed-array
            $arc->numArcs = $in->readVInt();
            $arc->bytesPerArc = $in->readVInt();
            $arc->arcIdx = -1;
            $arc->nextArc = $arc->posArcsStart = $in->getPosition();
            //System.out.println("  bytesPer=" + $arc->bytesPerArc + " numArcs=" + $arc->numArcs + " arcsStart=" + pos);
        } else {
            //$arc->flags = b;
            $arc->nextArc = $address;
            $arc->bytesPerArc = 0;
        }

        return $this->readNextRealArc($arc, $in);
    }

    /**
     * Checks if <code>arc</code>'s target state is in expanded (or vector) format.
     *
     * @return bool Returns <code>true</code> if <code>arc</code> points to a state in an
     * expanded array format.
     */
    public function isExpandedTarget(Arc $follow, BytesReader $in)
    {
        if (!$this->targetHasArcs($follow)) {
            return false;
        } else {
            $in->setPosition($follow->target);
            return $in->readByte() == self::ARCS_AS_FIXED_ARRAY;
        }
    }

    /** In-place read; returns the arc. */
    public function readNextArc(Arc $arc, BytesReader $in)
    {
        if ($arc->label == self::END_LABEL) {
            // This was a fake inserted "final" arc
            if ($arc->nextArc <= 0) {
                throw new IllegalArgumentException("cannot readNextArc when \$arc->isLast()=true");
            }
            return $this->readFirstRealTargetArc($arc->nextArc, $arc, $in);
        } else {
            return $this->readNextRealArc($arc, $in);
        }
    }

    /** Peeks at next arc's label; does not alter arc.  Do
     *  not call this if arc.isLast()! */
    public function readNextArcLabel(Arc $arc, BytesReader $in)
    {
//    assert !arc.isLast();
        if (arc . isLast()) {
            throw new \AssertionError('NOT: !arc.isLast()');
        }

        if ($arc->label == self::END_LABEL) {
            //System.out.println("    nextArc fake " +
            //$arc->nextArc);

            $pos = $arc->nextArc;
            $in->setPosition($pos);

            $b = $in->readByte();
            if ($b == self::ARCS_AS_FIXED_ARRAY) {
                //System.out.println("    nextArc fixed array");
                $in->readVInt();

                // Skip bytesPerArc:
                $in->readVInt();
            } else {
                $in->setPosition($pos);
            }
        } else {
            if ($arc->bytesPerArc != 0) {
                //System.out.println("    nextArc real array");
                // arcs are at fixed entries
                $in->setPosition($arc->posArcsStart);
                $in->skipBytes((1 + $arc->arcIdx) * $arc->bytesPerArc);
            } else {
                // arcs are packed
                //System.out.println("    nextArc real packed");
                $in->setPosition($arc->nextArc);
            }
        }
        // skip flags
        $in->readByte();
        return $this->readLabel($in);
    }

    /** Never returns null, but you should never call this if
     *  arc.isLast() is true. */
    public function readNextRealArc(Arc $arc, BytesReader $in)
    {
        // TODO: can't assert this because we call from readFirstArc
        // assert !flag(arc.flags, BIT_LAST_ARC);

        // this is a continuing arc in a fixed array
        if ($arc->bytesPerArc != 0) {
            // arcs are at fixed entries
            $arc->arcIdx++;
//        assert $arc->arcIdx < $arc->numArcs;
            if ($arc->arcIdx >= $arc->numArcs) {
                throw new \AssertionError('NOT: $arc->arcIdx < $arc->numArcs');
            }
            $in->setPosition($arc->posArcsStart);
            $in->skipBytes($arc->arcIdx * $arc->bytesPerArc);
        } else {
            // arcs are packed
            $in->setPosition($arc->nextArc);
        }
        $arc->flags = $in->readByte();
        $arc->label = $this->readLabel($in);

        if ($arc->flag(self::BIT_ARC_HAS_OUTPUT)) {
            $arc->output = $this->outputs->read($in);
        } else {
            $arc->output = $this->outputs->getNoOutput();
        }

        if ($arc->flag(self::BIT_ARC_HAS_FINAL_OUTPUT)) {
            $arc->nextFinalOutput = $this->outputs->readFinalOutput($in);
        } else {
            $arc->nextFinalOutput = $this->outputs->getNoOutput();
        }

        if ($arc->flag(self::BIT_STOP_NODE)) {
            if ($arc->flag(self::BIT_FINAL_ARC)) {
                $arc->target = self::FINAL_END_NODE;
            } else {
                $arc->target = self::NON_FINAL_END_NODE;
            }
            $arc->nextArc = $in->getPosition();
        } else {
            if ($arc->flag(self::BIT_TARGET_NEXT)) {
                $arc->nextArc = $in->getPosition();
                // TODO: would be nice to make this lazy -- maybe
                // caller doesn't need the target and is scanning arcs...
                if (!$arc->flag(self::BIT_LAST_ARC)) {
                    if ($arc->bytesPerArc == 0) {
                        // must scan
                        $this->seekToNextNode($in);
                    } else {
                        $in->setPosition($arc->posArcsStart);
                        $in->skipBytes($arc->bytesPerArc * $arc->numArcs);
                    }
                }
                $arc->target = $in->getPosition();
            } else {
                $arc->target = $this->readUnpackedNodeTarget($in);
                $arc->nextArc = $in->getPosition();
            }
        }
        return $arc;
    }

    // LUCENE-5152: called only from asserts, to validate that the
    // non-cached arc lookup would produce the same result, to
    // catch callers that illegally modify shared structures with
    // the result (we shallow-clone the Arc itself, but e.g. a BytesRef
    // output is still shared):
    private function assertRootCachedArc($label, Arc $cachedArc)
    {
        $arc = new Arc();
        $this->getFirstArc($arc);
        $in = $this->getBytesReader();
        $result = $this->findTargetArc($label, $arc, $arc, $in, false);
        if ($result == null) {
//        assert cachedArc == null;
            if ($cachedArc != null) {
                throw new \AssertionError('NOT: cachedArc == null');
            }
        } else {
//      assert cachedArc != null;
            if (!($cachedArc != null)) {
                throw new \AssertionError('NOT: cachedArc != null');
            }
//      assert cachedArc.arcIdx == result.arcIdx;
            if (!($cachedArc->arcIdx == $result->arcIdx)) {
                throw new \AssertionError('NOT: cachedArc.arcIdx == result.arcIdx');
            }
//      assert cachedArc.bytesPerArc == result.bytesPerArc;
            if (!($cachedArc->bytesPerArc == $result->bytesPerArc)) {
                throw new \AssertionError('NOT: cachedArc.bytesPerArc == result.bytesPerArc');
            }
//      assert cachedArc.flags == result.flags;
            if (!($cachedArc->flags == $result->flags)) {
                throw new \AssertionError('NOT: cachedArc.flags == result.flags');
            }
//      assert cachedArc.label == result.label;
            if (!($cachedArc->label == $result->label)) {
                throw new \AssertionError('NOT: cachedArc.label == result.label');
            }
//      assert cachedArc.nextArc == result.nextArc;
            if (!($cachedArc->nextArc == $result->nextArc)) {
                throw new \AssertionError('NOT: cachedArc.nextArc == result.nextArc');
            }
//      assert cachedArc.nextFinalOutput.equals(result.nextFinalOutput);
            if (!($cachedArc->nextFinalOutput == $result->nextFinalOutput)) {
                throw new \AssertionError('NOT: cachedArc.nextFinalOutput.equals(result.nextFinalOutput)');
            }
//      assert cachedArc.numArcs == result.numArcs;
            if (!($cachedArc->numArcs == $result->numArcs)) {
                throw new \AssertionError('NOT: cachedArc.numArcs == result.numArcs');
            }
//      assert cachedArc.output.equals(result.output);
            if (!($cachedArc->output == $result->output)) {
                throw new \AssertionError('NOT: cachedArc.output.equals(result.output)');
            }
//      assert cachedArc.posArcsStart == result.posArcsStart;
            if (!($cachedArc->posArcsStart == $result->posArcsStart)) {
                throw new \AssertionError('NOT: cachedArc.posArcsStart == result.posArcsStart');
            }
//      assert cachedArc.target == result.target;
            if (!($cachedArc->target == $result->target)) {
                throw new \AssertionError('NOT: cachedArc.target == result.target');
            }
        }

        return true;
    }

    // TODO: could we somehow [partially] tableize arc lookups
    // like automaton?

    /** Finds an arc leaving the incoming arc, replacing the arc in place.
     *  This returns null if the arc was not found, else the incoming arc. */
    public function findTargetArc($labelToMatch, Arc $follow, Arc $arc, BytesReader $in, $useRootArcCache = true)
    {
        if ($labelToMatch == self::END_LABEL) {
            if ($follow->isFinal()) {
                if ($follow->target <= 0) {
                    $arc->flags = self::BIT_LAST_ARC;
                } else {
                    $arc->flags = 0;
                    // NOTE: nextArc is a node (not an address!) in this case:
                    $arc->nextArc = $follow->target;
                }
                $arc->output = $follow->nextFinalOutput;
                $arc->label = self::END_LABEL;
                return $arc;
            } else {
                return null;
            }
        }

        // Short-circuit if this arc is in the root arc cache:
        if ($useRootArcCache && $this->cachedRootArcs != null && $follow->target == $this->startNode && $labelToMatch < count($this->cachedRootArcs)) {
            $result = $this->cachedRootArcs[$labelToMatch];

            // LUCENE-5152: detect tricky cases where caller
            // modified previously returned cached root-arcs:
//      assert $this->assertRootCachedArc($labelToMatch, $result);
            if (!$this->assertRootCachedArc($labelToMatch, $result)) {
                throw new \AssertionError('NOT: $this->assertRootCachedArc($labelToMatch, $result)');
            }

            if ($result == null) {
                return null;
            } else {
                $arc->copyFrom($result);
                return $arc;
            }
        }

        if (!$this->targetHasArcs($follow)) {
            return null;
        }

        $in->setPosition($follow->target);

        // System.out.println("fta label=" + (char) labelToMatch);

        if ($in->readByte() == self::ARCS_AS_FIXED_ARRAY) {
            // Arcs are full array; do binary search:
            $arc->numArcs = $in->readVInt();
            $arc->bytesPerArc = $in->readVInt();
            $arc->posArcsStart = $in->getPosition();
            $low = 0;
            $high = $arc->numArcs - 1;
            while ($low <= $high) {
                //System.out.println("    cycle");
                $mid = ($low + $high) >> 1;
                $in->setPosition($arc->posArcsStart);
                $in->skipBytes($arc->bytesPerArc * $mid + 1);
                $midLabel = $this->readLabel($in);
                $cmp = $midLabel - $labelToMatch;
                if ($cmp < 0) {
                    $low = $mid + 1;
                } else {
                    if ($cmp > 0) {
                        $high = $mid - 1;
                    } else {
                        $arc->arcIdx = $mid - 1;
                        //System.out.println("    found!");
                        return $this->readNextRealArc($arc, $in);
                    }
                }
            }

            return null;
        }

        // Linear scan
        $this->readFirstRealTargetArc($follow->target, $arc, $in);

        while (true) {
            //System.out.println("  non-bs cycle");
            // TODO: we should fix this code to not have to create
            // object for the output of every arc we scan... only
            // for the matching arc, if found
            if ($arc->label == $labelToMatch) {
                //System.out.println("    found!");
                return $arc;
            } else {
                if ($arc->label > $labelToMatch) {
                    return null;
                } else {
                    if ($arc->isLast()) {
                        return null;
                    } else {
                        $this->readNextRealArc($arc, $in);
                    }
                }
            }
        }
    }

    private function seekToNextNode(BytesReader $in)
    {

        while (true) {

            $flags = $in->readByte();
            $this->readLabel($in);

            if ($this->flag($flags, self::BIT_ARC_HAS_OUTPUT)) {
                $this->outputs->skipOutput($in);
            }

            if ($this->flag($flags, self::BIT_ARC_HAS_FINAL_OUTPUT)) {
                $this->outputs->skipFinalOutput($in);
            }

            if (!$this->flag($flags, self::BIT_STOP_NODE) && !$this->flag($flags, self::BIT_TARGET_NEXT)) {
                $this->readUnpackedNodeTarget($in);
            }

            if ($this->flag($flags, self::BIT_LAST_ARC)) {
                return;
            }
        }
    }

    /**
     * Nodes will be expanded if their depth (distance from the root node) is
     * &lt;= this value and their number of arcs is &gt;=
     * {@link #FIXED_ARRAY_NUM_ARCS_SHALLOW}.
     *
     * <p>
     * Fixed array consumes more RAM but enables binary search on the arcs
     * (instead of a linear scan) on lookup by arc label.
     *
     * @return <code>true</code> if <code>node</code> should be stored in an
     *         expanded (array) form.
     *
     * @see    #FIXED_ARRAY_NUM_ARCS_DEEP
     * @see    Builder.UnCompiledNode#depth
     */
    private function shouldExpand(Builder $builder, UnCompiledNode $node)
    {
        return $builder->allowArrayArcs &&
            (($node->depth <= self::FIXED_ARRAY_SHALLOW_DISTANCE && $node->numArcs >= self::FIXED_ARRAY_NUM_ARCS_SHALLOW) ||
                $node->numArcs >= self::FIXED_ARRAY_NUM_ARCS_DEEP);
    }

    /** Returns a {@link BytesReader} for this FST, positioned at
     *  position 0. */
    public function getBytesReader()
    {
        if ($this->fstStore != null) {
            return $this->fstStore->getReverseBytesReader();
        } else {
            return $this->bytes->getReverseReader();
        }
    }
}

