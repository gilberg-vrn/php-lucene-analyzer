<?php

namespace ftIndex\analyses\hunspell;

use ftIndex\store\DataInput;
use ftIndex\store\DataOutput;

class CodecUtil
{

    const ID_LENGTH = 16;

    protected function __construct()
    {
    }

    /**
     * Constant to identify the start of a codec header.
     */
    const CODEC_MAGIC = 0x3fd76c17;
    /**
     * Constant to identify the start of a codec footer.
     */
    const FOOTER_MAGIC = ~self::CODEC_MAGIC;

    /**
     * Writes a codec header, which records both a string to
     * identify the file and a version number. This header can
     * be parsed and validated with
     * {@link #checkHeader(DataInput, String, int, int) checkHeader()}.
     * <p>
     * CodecHeader --&gt; Magic,CodecName,Version
     * <ul>
     *    <li>Magic --&gt; {@link DataOutput#writeInt Uint32}. This
     *        identifies the start of the header. It is always {@value #CODEC_MAGIC}.
     *    <li>CodecName --&gt; {@link DataOutput#writeString String}. This
     *        is a string to identify this file.
     *    <li>Version --&gt; {@link DataOutput#writeInt Uint32}. Records
     *        the version of the file.
     * </ul>
     * <p>
     * Note that the length of a codec header depends only upon the
     * name of the codec, so this length can be computed at any time
     * with {@link #headerLength(String)}.
     *
     * @param DataOutput $out     Output stream
     * @param string     $codec   String to identify this file. It should be simple ASCII,
     *                            less than 128 characters in length.
     * @param int        $version Version number
     *
     * @throws IOException If there is an I/O error writing to the underlying medium.
     * @throws IllegalArgumentException If the codec name is not simple ASCII, or is more than 127 characters in length
     */
    public static function writeHeader(DataOutput $out, $codec, $version)
    {
        if (mb_strlen($codec) != strlen($codec) || strlen($codec) >= 128) {
            throw new IllegalArgumentException("codec must be simple ASCII, less than 128 characters in length [got {$codec}]");
        }
        $out->writeInt(self::CODEC_MAGIC);
        $out->writeString($codec);
        $out->writeInt($version);
    }

    /**
     * Writes a codec header for an index file, which records both a string to
     * identify the format of the file, a version number, and data to identify
     * the file instance (ID and auxiliary suffix such as generation).
     * <p>
     * This header can be parsed and validated with
     * {@link #checkIndexHeader(DataInput, String, int, int, byte[], String) checkIndexHeader()}.
     * <p>
     * IndexHeader --&gt; CodecHeader,ObjectID,ObjectSuffix
     * <ul>
     *    <li>CodecHeader   --&gt; {@link #writeHeader}
     *    <li>ObjectID     --&gt; {@link DataOutput#writeByte byte}<sup>16</sup>
     *    <li>ObjectSuffix --&gt; SuffixLength,SuffixBytes
     *    <li>SuffixLength  --&gt; {@link DataOutput#writeByte byte}
     *    <li>SuffixBytes   --&gt; {@link DataOutput#writeByte byte}<sup>SuffixLength</sup>
     * </ul>
     * <p>
     * Note that the length of an index header depends only upon the
     * name of the codec and suffix, so this length can be computed at any time
     * with {@link #indexHeaderLength(String,String)}.
     *
     * @param DataOutput $out     Output stream
     * @param string     $codec   String to identify the format of this file. It should be simple ASCII,
     *                            less than 128 characters in length.
     * @param string     $id      Unique identifier for this particular file instance.
     * @param string     $suffix  auxiliary suffix information for the file. It should be simple ASCII,
     *                            less than 256 characters in length.
     * @param int        $version Version number
     *
     * @throws IOException If there is an I/O error writing to the underlying medium.
     * @throws IllegalArgumentException If the codec name is not simple ASCII, or
     *         is more than 127 characters in length, or if id is invalid,
     *         or if the suffix is not simple ASCII, or more than 255 characters
     *         in length.
     */
    public static function writeIndexHeader(DataOutput $out, $codec, $version, $id, $suffix)
    {
        if (strlen($id) != self::ID_LENGTH) {
            throw new IllegalArgumentException("Invalid id: {$id}");
        }
        self::writeHeader($out, $codec, $version);
        $out->writeBytes($id, 0, strlen($id));
        if (strlen($suffix) != mb_strlen($suffix) || strlen($suffix) >= 256) {
            throw new IllegalArgumentException("suffix must be simple ASCII, less than 256 characters in length [got {$suffix}]");
        }
        $out->writeByte(strlen($suffix));
        $out->writeBytes($suffix, 0, strlen($suffix));
    }

    /**
     * Computes the length of a codec header.
     *
     * @param string $codec Codec name.
     *
     * @return int length of the entire codec header.
     * @see #writeHeader(DataOutput, String, int)
     */
    public static function headerLength($codec)
    {
        return 9 + strlen($codec);
    }

    /**
     * Computes the length of an index header.
     *
     * @param string $codec Codec name.
     * @param string $suffix
     *
     * @return int length of the entire index header.
     * @see #writeIndexHeader(DataOutput, String, int, byte[], String)
     */
    public static function indexHeaderLength($codec, $suffix)
    {
        return self::headerLength($codec) + self::ID_LENGTH + 1 + strlen($suffix);
    }

    /**
     * Reads and validates a header previously written with
     * {@link #writeHeader(DataOutput, String, int)}.
     * <p>
     * When reading a file, supply the expected <code>codec</code> and
     * an expected version range (<code>minVersion to maxVersion</code>).
     *
     * @param DataInput $in         Input stream, positioned at the point where the
     *                              header was previously written. Typically this is located
     *                              at the beginning of the file.
     * @param string    $codec      The expected codec name.
     * @param int       $minVersion The minimum supported expected version number.
     * @param int       $maxVersion The maximum supported expected version number.
     *
     * @return int The actual version found, when a valid header is found
     *         that matches <code>codec</code>, with an actual version
     *         where {@code minVersion <= actual <= maxVersion}.
     *         Otherwise an exception is thrown.
     * @throws CorruptIndexException If the first four bytes are not
     *         {@link #CODEC_MAGIC}, or if the actual codec found is
     *         not <code>codec</code>.
     * @throws IndexFormatTooOldException If the actual version is less
     *         than <code>minVersion</code>.
     * @throws IndexFormatTooNewException If the actual version is greater
     *         than <code>maxVersion</code>.
     * @throws IOException If there is an I/O error reading from the underlying medium.
     * @see #writeHeader(DataOutput, String, int)
     */
    public static function checkHeader(DataInput $in, $codec, $minVersion, $maxVersion)
    {
        // Safety to guard against reading a bogus string:
        $actualHeader = $in->readInt();
        if ($actualHeader != self::CODEC_MAGIC) {
            throw new CorruptIndexException("codec header mismatch: actual header={$actualHeader} vs expected header=" . self::CODEC_MAGIC);
        }
        return self::checkHeaderNoMagic($in, $codec, $minVersion, $maxVersion);
    }

    /** Like {@link
     *  #checkHeader(DataInput,String,int,int)} except this
     *  version assumes the first int has already been read
     *  and validated from the input. */
    public static function checkHeaderNoMagic(DataInput $in, $codec, $minVersion, $maxVersion)
    {
        $actualCodec = $in->readString();
        if ($actualCodec != $codec) {
            throw new CorruptIndexException("codec mismatch: actual codec=" + actualCodec + " vs expected codec=" + codec, in);
        }

        $actualVersion = $in->readInt();
        if ($actualVersion < $minVersion) {
            throw new IndexFormatTooOldException($in, $actualVersion, $minVersion, $maxVersion);
        }
        if ($actualVersion > $maxVersion) {
            throw new IndexFormatTooNewException($in, $actualVersion, $minVersion, $maxVersion);
        }

        return $actualVersion;
    }

    /**
     * Reads and validates a header previously written with
     * {@link #writeIndexHeader(DataOutput, String, int, byte[], String)}.
     * <p>
     * When reading a file, supply the expected <code>codec</code>,
     * expected version range (<code>minVersion to maxVersion</code>),
     * and object ID and suffix.
     *
     * @param DataInput $in             Input stream, positioned at the point where the
     *                                  header was previously written. Typically this is located
     *                                  at the beginning of the file.
     * @param string    $codec          The expected codec name.
     * @param int       $minVersion     The minimum supported expected version number.
     * @param int       $maxVersion     The maximum supported expected version number.
     * @param string    $expectedID     The expected object identifier for this file.
     * @param string    $expectedSuffix The expected auxiliary suffix for this file.
     *
     * @return int The actual version found, when a valid header is found
     *         that matches <code>codec</code>, with an actual version
     *         where {@code minVersion <= actual <= maxVersion},
     *         and matching <code>expectedID</code> and <code>expectedSuffix</code>
     *         Otherwise an exception is thrown.
     * @throws CorruptIndexException If the first four bytes are not
     *         {@link #CODEC_MAGIC}, or if the actual codec found is
     *         not <code>codec</code>, or if the <code>expectedID</code>
     *         or <code>expectedSuffix</code> do not match.
     * @throws IndexFormatTooOldException If the actual version is less
     *         than <code>minVersion</code>.
     * @throws IndexFormatTooNewException If the actual version is greater
     *         than <code>maxVersion</code>.
     * @throws IOException If there is an I/O error reading from the underlying medium.
     * @see #writeIndexHeader(DataOutput, String, int, byte[],String)
     */
    public static function checkIndexHeader(DataInput $in, $codec, $minVersion, $maxVersion, $expectedID, $expectedSuffix)
    {
        $version = self::checkHeader($in, $codec, $minVersion, $maxVersion);
        self::checkIndexHeaderID($in, $expectedID);
        self::checkIndexHeaderSuffix($in, $expectedSuffix);

        return $version;
    }

    /**
     * Expert: verifies the incoming {@link IndexInput} has an index header
     * and that its segment ID matches the expected one, and then copies
     * that index header into the provided {@link DataOutput}.  This is
     * useful when building compound files.
     *
     * @param IndexInput $in         Input stream, positioned at the point where the
     *                               index header was previously written. Typically this is located
     *                               at the beginning of the file.
     * @param DataOutput $out        Output stream, where the header will be copied to.
     * @param string     $expectedID Expected segment ID
     *
     * @throws CorruptIndexException If the first four bytes are not
     *         {@link #CODEC_MAGIC}, or if the <code>expectedID</code>
     *         does not match.
     * @throws IOException If there is an I/O error reading from the underlying medium.
     *
     * @lucene.internal
     */
    public static function verifyAndCopyIndexHeader(IndexInput $in, DataOutput $out, $expectedID)
    {
        // make sure it's large enough to have a header and footer
        if ($in->length() < self::footerLength() + self::headerLength("")) {
            throw new CorruptIndexException("compound sub-files must have a valid codec header and footer: file is too small (" . $in->length() . " bytes)", $in);
        }

        $actualHeader = $in->readInt();
        if ($actualHeader != self::CODEC_MAGIC) {
            throw new CorruptIndexException("compound sub-files must have a valid codec header and footer: codec header mismatch: actual header=" . $actualHeader . " vs expected header=" . self::CODEC_MAGIC, $in);
        }

        // we can't verify these, so we pass-through:
        $codec = $in->readString();
        $version = $in->readInt();

        // verify id:
        self::checkIndexHeaderID($in, $expectedID);

        // we can't verify extension either, so we pass-through:
        $suffixLength = $in->readByte() & 0xFF;
        $suffixBytes = []; //new byte[suffixLength];
        $in->readBytes($suffixBytes, 0, $suffixLength);

        // now write the header we just verified
        $out->writeInt(self::CODEC_MAGIC);
        $out->writeString($codec);
        $out->writeInt($version);
        $out->writeBytes($expectedID, 0, strlen($expectedID));
        $out->writeByte($suffixLength);
        $out->writeBytes($suffixBytes, 0, $suffixLength);
    }


    /** Retrieves the full index header from the provided {@link IndexInput}.
     *  This throws {@link CorruptIndexException} if this file does
     * not appear to be an index file. */
    public static function readIndexHeader(IndexInput $in)
    {
        $in->seek(0);
        $actualHeader = $in->readInt();
        if ($actualHeader != self::CODEC_MAGIC) {
            throw new CorruptIndexException("codec header mismatch: actual header={$actualHeader} vs expected header=" . self::CODEC_MAGIC, $in);
        }
        $codec = $in->readString();
        $in->readInt();
        $in->seek($in->getFilePointer() + self::ID_LENGTH);
        $suffixLength = $in->readByte() & 0xFF;
        $bytes = []; //new byte[headerLength(codec) + StringHelper.ID_LENGTH + 1 + suffixLength];
        $bytesLen = self::headerLength($codec) + self::ID_LENGTH + 1 + $suffixLength;
        $in->seek(0);
        $in->readBytes($bytes, 0, $bytesLen);

        return $bytes;
    }

    /** Retrieves the full footer from the provided {@link IndexInput}.  This throws
     *  {@link CorruptIndexException} if this file does not have a valid footer. */
    public static function readFooter(IndexInput $in)
    {
        if ($in->length() < self::footerLength()) {
            throw new CorruptIndexException("misplaced codec footer (file truncated?): length={$in->length()} but footerLength==" . self::footerLength(), $in);
        }
        $in->seek($in->length() - self::footerLength());
        self::validateFooter($in);
        $in->seek($in->length() - self::footerLength());
        $bytes = [];
        $in->readBytes($bytes, 0, self::footerLength());

        return $bytes;
    }

    /** Expert: just reads and verifies the object ID of an index header */
    public static function checkIndexHeaderID(DataInput $in, $expectedID)
    {
        $id = []; //new byte[StringHelper.ID_LENGTH];
        $in->readBytes($id, 0, self::ID_LENGTH);
        if ($id != $expectedID) {
            throw new CorruptIndexException("file mismatch, expected id={$expectedID}, got={$id}", $in);
        }
        return $id;
    }

    /** Expert: just reads and verifies the suffix of an index header */
    public static function checkIndexHeaderSuffix(DataInput $in, $expectedSuffix)
    {
        $suffixLength = $in->readByte() & 0xFF;
        $suffixBytes = []; //new byte[$suffixLength];
        $in->readBytes($suffixBytes, 0, $suffixLength);
        $suffix = $suffixBytes;
        if ($suffix != $expectedSuffix) {
            throw new CorruptIndexException("file mismatch, expected suffix={$expectedSuffix}, got={$suffix}", $in);
        }

        return $suffix;
    }

    /**
     * Writes a codec footer, which records both a checksum
     * algorithm ID and a checksum. This footer can
     * be parsed and validated with
     * {@link #checkFooter(ChecksumIndexInput) checkFooter()}.
     * <p>
     * CodecFooter --&gt; Magic,AlgorithmID,Checksum
     * <ul>
     *    <li>Magic --&gt; {@link DataOutput#writeInt Uint32}. This
     *        identifies the start of the footer. It is always {@value #FOOTER_MAGIC}.
     *    <li>AlgorithmID --&gt; {@link DataOutput#writeInt Uint32}. This
     *        indicates the checksum algorithm used. Currently this is always 0,
     *        for zlib-crc32.
     *    <li>Checksum --&gt; {@link DataOutput#writeLong Uint64}. The
     *        actual checksum value for all previous bytes in the stream, including
     *        the bytes from Magic and AlgorithmID.
     * </ul>
     *
     * @param out Output stream
     *
     * @throws IOException If there is an I/O error writing to the underlying medium.
     */
    public static function writeFooter(IndexOutput $out)
    {
        $out->writeInt(self::FOOTER_MAGIC);
        $out->writeInt(0);

        self::writeCRC($out);
    }

    /**
     * Computes the length of a codec footer.
     *
     * @return int length of the entire codec footer.
     * @see #writeFooter(IndexOutput)
     */
    public static function footerLength()
    {
        return 16;
    }

    /**
     * Validates the codec footer previously written by {@link #writeFooter}.
     * @return int actual checksum value
     * @throws IOException if the footer is invalid, if the checksum does not match,
     *                     or if {@code in} is not properly positioned before the footer
     *                     at the end of the stream.
     */
    public static function checkFooter(ChecksumIndexInput $in)
    {
        self::validateFooter($in);
        $actualChecksum = $in->getChecksum();
        $expectedChecksum = self::readCRC($in);
        if ($expectedChecksum != $actualChecksum) {
            throw new CorruptIndexException("checksum failed (hardware problem?) : expected=" . sprintf('%2x', $expectedChecksum) .
                " actual=" . sprinf('%2x', $actualChecksum), $in);
        }
        return $actualChecksum;
    }

    /**
     * Validates the codec footer previously written by {@link #writeFooter}, optionally
     * passing an unexpected exception that has already occurred.
     * <p>
     * When a {@code priorException} is provided, this method will add a suppressed exception
     * indicating whether the checksum for the stream passes, fails, or cannot be computed, and
     * rethrow it. Otherwise it behaves the same as {@link #checkFooter(ChecksumIndexInput)}.
     * <p>
     * Example usage:
     * <pre class="prettyprint">
     * try (ChecksumIndexInput input = ...) {
     *   Throwable priorE = null;
     *   try {
     *     // ... read a bunch of stuff ...
     *   } catch (Throwable exception) {
     *     priorE = exception;
     *   } finally {
     *     CodecUtil.checkFooter(input, priorE);
     *   }
     * }
     * </pre>
     *
     * @param ChecksumIndexInput $in
     * @param \Throwable         $priorException
     *
     * @throws IOException
     */
    public static function checkFooterThrowable(ChecksumIndexInput $in, Throwable $priorException)
    {
        if ($priorException == null) {
            self::checkFooter($in);
        } else {
            try {
                $remaining = $in->length() - $in->getFilePointer();
                if ($remaining < self::footerLength()) {
                    // corruption caused us to read into the checksum footer already: we can't proceed
                    $priorException->addSuppressed(new CorruptIndexException("checksum status indeterminate: remaining=" . $remaining .
                        ", please run checkindex for more details", $in));
                } else {
                    // otherwise, skip any unread bytes.
                    $in->skipBytes($remaining - self::footerLength());

                    // now check the footer
                    try {
                        $checksum = self::checkFooter($in);
                        $priorException->addSuppressed(new CorruptIndexException("checksum passed (" . sprintf('%2x', $checksum) .
                            "). possibly transient resource issue, or a Lucene or JVM bug", $in));
                    } catch (CorruptIndexException $t) {
                        $priorException->addSuppressed($t);
                    }
                }
            } catch (\Throwable $t) {
                // catch-all for things that shouldn't go wrong (e.g. OOM during readInt) but could...
                $priorException->addSuppressed(new CorruptIndexException("checksum status indeterminate: unexpected exception", in, t));
            }
            throw $priorException;
        }
    }

    /**
     * Returns (but does not validate) the checksum previously written by {@link #checkFooter}.
     *
     * @param IndexInput $in
     *
     * @return int actual checksum value
     * @throws CorruptIndexException
     */
    public static function retrieveChecksum(IndexInput $in)
    {
        if ($in->length() < self::footerLength()) {
            throw new CorruptIndexException("misplaced codec footer (file truncated?): length=" . $in->length() . " but footerLength==" . self::footerLength(), $in);
        }
        $in->seek($in->length() - self::footerLength());
        self::validateFooter($in);
        return self::readCRC($in);
    }

    private static function validateFooter(IndexInput $in)
    {
        $remaining = $in->length() - $in->getFilePointer();
        $expected = self::footerLength();
        if ($remaining < $expected) {
            throw new CorruptIndexException("misplaced codec footer (file truncated?): remaining=" . $remaining . ", expected=" . $expected . ", fp=" . $in->getFilePointer(), 0, $in);
        } else {
            if ($remaining > $expected) {
                throw new CorruptIndexException("misplaced codec footer (file extended?): remaining=" . $remaining . ", expected=" . $expected . ", fp=" . $in->getFilePointer(), 0, $in);
            }
        }

        $magic = $in->readInt();
        if ($magic != self::FOOTER_MAGIC) {
            throw new CorruptIndexException("codec footer mismatch (file truncated?): actual footer=" . $magic . " vs expected footer=" . self::FOOTER_MAGIC, 0, in);
        }

        $algorithmID = $in->readInt();
        if ($algorithmID != 0) {
            throw new CorruptIndexException("codec footer mismatch: unknown algorithmID: " . $algorithmID, 0, $in);
        }
    }

    /**
     * Clones the provided input, reads all bytes from the file, and calls {@link #checkFooter}
     * <p>
     * Note that this method may be slow, as it must process the entire file.
     * If you just need to extract the checksum value, call {@link #retrieveChecksum}.
     */
    public static function checksumEntireFile(IndexInput $input)
    {
        $clone = $input->clone();
        $clone->seek(0);
        $in = new BufferedChecksumIndexInput($clone);
//assert in.getFilePointer() == 0;
        if ($in->getFilePointer() != 0) {
            throw new \AssertionError('Not: in.getFilePointer() == 0');
        }
        if ($in->length() < self::footerLength()) {
            throw new CorruptIndexException("misplaced codec footer (file truncated?): length=" . $in->length() . " but footerLength==" . self::footerLength(), $input);
        }
        $in->seek($in->length() - self::footerLength());
        return self::checkFooter($in);
    }

  /**
   * Reads CRC32 value as a 64-bit long from the input.
   * @throws CorruptIndexException if CRC is formatted incorrectly (wrong bits set)
   * @throws IOException if an i/o error occurs
   */
  static public function readCRC(IndexInput $input)
  {
    $value = $input->readLong();
    if (($value & 0xFFFFFFFF00000000) != 0) {
        throw new CorruptIndexException("Illegal CRC-32 checksum: " . $value, $input);
    }
    return $value;
  }

  /**
   * Writes CRC32 value as a 64-bit long to the output.
   * @throws IllegalStateException if CRC is formatted incorrectly (wrong bits set)
   * @throws IOException if an i/o error occurs
   */
  static public function writeCRC(IndexOutput $output)
  {
    $value = $output->getChecksum();
    if (($value & 0xFFFFFFFF00000000) != 0) {
        throw new IllegalStateException("Illegal CRC-32 checksum: " . $value + " (resource=" . $output + ")");
    }
    $output->writeLong($value);
  }
}

class CorruptIndexException extends \Exception {}
class IndexFormatTooOldException extends \Exception {}