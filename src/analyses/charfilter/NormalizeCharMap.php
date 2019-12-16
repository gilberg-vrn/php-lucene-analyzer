<?php

namespace ftIndex\analyses\charfilter;

use ftIndex\analyses\hunspell\SimpleFST;

/**
 * Class NormalizeCharMap
 *
 * @package ftIndex\analyses\charfilter
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    10/3/19 8:02 PM
 */
class NormalizeCharMap
{
    protected $map;
    protected $cachedRootArcs = []; //new HashMap<>();

    // Use the builder to create:
    public function __construct(SimpleFST $map)
    {
        $this->map = $map;
//    if ($this->map != null) {
//      try {
//         Pre-cache root arcs:
//        final FST.Arc<CharsRef> scratchArc = new FST.Arc<>();
//        final FST.BytesReader fstReader = map.getBytesReader();
//        map.getFirstArc(scratchArc);
//        if (FST.targetHasArcs(scratchArc)) {
//          map.readFirstRealTargetArc(scratchArc.target, scratchArc, fstReader);
//          while(true) {
//            assert scratchArc.label != FST.END_LABEL;
//            cachedRootArcs.put(Character.valueOf((char) scratchArc.label), new FST.Arc<CharsRef>().copyFrom(scratchArc));
//            if (scratchArc.isLast()) {
//              break;
//            }
//map.readNextRealArc(scratchArc, fstReader);
//}
//}
//System.out.println("cached " + cachedRootArcs.size() + " root arcs");
//} catch (IOException ioe) {
//     Bogus FST IOExceptions!!  (will never happen)
//    throw new RuntimeException(ioe);
//}
//    }
    }
}