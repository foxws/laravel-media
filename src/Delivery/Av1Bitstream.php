<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Foxws\Media\Exceptions\InvalidMediaException;

/**
 * Finds the tiles in the temporal units of an AV1 track, so Common Encryption can protect the tile
 * data and leave the OBU headers, sequence and frame headers and tile sizes readable, as the AV1
 * ISOBMFF binding requires. Frame headers are read bit by bit (AV1 specification, section 5), and
 * the reference frame state they depend on is kept across the samples of a segment, which starts
 * on a key frame.
 *
 * @phpstan-type Sequence array{reduced: bool, decoderModel: bool, equalPictureInterval: bool, bufferRemovalTimeLength: int, presentationTimeLength: int, operatingPoints: list<array{idc: int, decoderModel: bool}>, operatingPointIdc: int, frameWidthBits: int, frameHeightBits: int, maxFrameWidth: int, maxFrameHeight: int, frameIdLength: int, deltaFrameIdLength: int, use128x128Superblock: bool, enableWarpedMotion: bool, enableOrderHint: bool, enableRefFrameMvs: bool, forceScreenContentTools: int, forceIntegerMv: int, orderHintBits: int, enableSuperres: bool, enableCdef: bool, enableRestoration: bool, monochrome: bool, numPlanes: int, subsamplingX: int, subsamplingY: int, separateUvDeltaQ: bool, filmGrain: bool}
 *
 * @internal
 */
final class Av1Bitstream
{
    protected const int OBU_SEQUENCE_HEADER = 1;

    protected const int OBU_TEMPORAL_DELIMITER = 2;

    protected const int OBU_FRAME_HEADER = 3;

    protected const int OBU_TILE_GROUP = 4;

    protected const int OBU_FRAME = 6;

    protected const int OBU_REDUNDANT_FRAME_HEADER = 7;

    protected const int KEY_FRAME = 0;

    protected const int INTER_FRAME = 1;

    protected const int INTRA_ONLY_FRAME = 2;

    protected const int SWITCH_FRAME = 3;

    protected const int PRIMARY_REF_NONE = 7;

    protected const int SELECT = 2;

    protected const int ALL_FRAMES = 0xFF;

    /**
     * The segmentation feature bits, whether they're signed and their limits, by feature.
     */
    protected const array SEGMENTATION_FEATURE_BITS = [8, 6, 6, 6, 6, 3, 0, 0];

    protected const array SEGMENTATION_FEATURE_SIGNED = [true, true, true, true, true, false, false, false];

    protected const array SEGMENTATION_FEATURE_MAX = [255, 63, 63, 63, 63, 7, 0, 0];

    /**
     * @var Sequence|null
     */
    protected ?array $sequence = null;

    /**
     * The reference frame slots: what later frame headers read from the frames they refer to.
     *
     * @var list<array{frameType: int, upscaledWidth: int, frameHeight: int, orderHint: int, featureEnabled: list<list<bool>>, featureData: list<list<int>>}>
     */
    protected array $slots = [];

    /**
     * The tile layout of the frame whose tile groups follow, once its header has been read.
     *
     * @var array{tileCols: int, tileRows: int, tileBits: int, tileSizeBytes: int}|null
     */
    protected ?array $frame = null;

    protected string $data = '';

    protected int $bit = 0;

    /**
     * @param  string  $configObus  The OBUs of the av1C box, usually the sequence header.
     *
     * @throws InvalidMediaException
     */
    public function __construct(string $configObus = '')
    {
        $this->slots = array_fill(0, 8, $this->slot(self::KEY_FRAME, 0, 0, 0));
        $this->tiles($configObus);
    }

    /**
     * Where the tile data of a temporal unit is, as byte offsets and lengths in the sample, in order.
     *
     * @return list<array{int, int}>
     *
     * @throws InvalidMediaException
     */
    public function tiles(string $sample): array
    {
        $tiles = [];
        $position = 0;
        $length = strlen($sample);

        while ($position < $length) {
            $header = ord($sample[$position++]);
            $type = ($header >> 3) & 0xF;
            [$temporalId, $spatialId] = [0, 0];

            if (($header & 0x4) !== 0) {
                $extension = ord($sample[$position++] ?? throw InvalidMediaException::unreadableBitstream('av1'));
                [$temporalId, $spatialId] = [$extension >> 5, ($extension >> 3) & 0x3];
            }

            $size = ($header & 0x2) !== 0 ? $this->leb128($sample, $position) : $length - $position;

            if ($position + $size > $length) {
                throw InvalidMediaException::unreadableBitstream('av1');
            }

            foreach ($this->obu($type, substr($sample, $position, $size), $temporalId, $spatialId) as [$offset, $tileSize]) {
                $tiles[] = [$position + $offset, $tileSize];
            }

            $position += $size;
        }

        return $tiles;
    }

    /**
     * @return list<array{int, int}> The tiles of the OBU, as offsets in its payload and lengths.
     *
     * @throws InvalidMediaException
     */
    protected function obu(int $type, string $payload, int $temporalId, int $spatialId): array
    {
        if ($type === self::OBU_SEQUENCE_HEADER) {
            $this->read($payload);
            $this->sequenceHeader();

            return [];
        }

        if ($type === self::OBU_TEMPORAL_DELIMITER) {
            $this->frame = null;

            return [];
        }

        if (! $this->inOperatingPoint($temporalId, $spatialId)) {
            return [];
        }

        if ($type === self::OBU_FRAME_HEADER || $type === self::OBU_REDUNDANT_FRAME_HEADER) {
            // A frame header repeated before the frame's last tile group is a copy.
            if ($this->frame === null) {
                $this->read($payload);
                $this->frameHeader($temporalId, $spatialId);
            }

            return [];
        }

        if ($type === self::OBU_FRAME) {
            $this->read($payload);
            $this->frameHeader($temporalId, $spatialId);
            $this->byteAlign();

            return $this->tileGroup();
        }

        if ($type === self::OBU_TILE_GROUP) {
            $this->read($payload);

            return $this->tileGroup();
        }

        return [];
    }

    /**
     * Whether the decoder keeps OBUs of these layers, for the first operating point.
     */
    protected function inOperatingPoint(int $temporalId, int $spatialId): bool
    {
        $idc = $this->sequence !== null ? $this->sequence['operatingPointIdc'] : 0;

        return $idc === 0 || ((($idc >> $temporalId) & 1) === 1 && (($idc >> ($spatialId + 8)) & 1) === 1);
    }

    /**
     * Read a tile group from the current position, after the frame header in a frame OBU.
     *
     * @return list<array{int, int}>
     *
     * @throws InvalidMediaException
     */
    protected function tileGroup(): array
    {
        $frame = $this->frame ?? throw InvalidMediaException::unreadableBitstream('av1');
        $count = $frame['tileCols'] * $frame['tileRows'];
        [$first, $last] = [0, $count - 1];

        if ($count > 1 && $this->bits(1) === 1) {
            [$first, $last] = [$this->bits($frame['tileBits']), $this->bits($frame['tileBits'])];
        }

        $this->byteAlign();
        $position = intdiv($this->bit, 8);
        $length = strlen($this->data);
        $tiles = [];

        for ($tile = $first; $tile <= $last; $tile++) {
            $size = $length - $position;

            if ($tile < $last) {
                $size = (int) hexdec(bin2hex(strrev(substr($this->data, $position, $frame['tileSizeBytes'])))) + 1;
                $position += $frame['tileSizeBytes'];
            }

            if ($size < 0 || $position + $size > $length) {
                throw InvalidMediaException::unreadableBitstream('av1');
            }

            $tiles[] = [$position, $size];
            $position += $size;
        }

        if ($last >= $count - 1) {
            $this->frame = null;
        }

        return $tiles;
    }

    /**
     * @throws InvalidMediaException
     */
    protected function sequenceHeader(): void
    {
        $profile = $this->bits(3);
        $this->bits(1);
        $reduced = $this->bits(1) === 1;
        $sequence = [
            'reduced' => $reduced,
            'decoderModel' => false,
            'equalPictureInterval' => false,
            'bufferRemovalTimeLength' => 0,
            'presentationTimeLength' => 0,
            'operatingPoints' => [],
            'operatingPointIdc' => 0,
        ];

        if ($reduced) {
            $this->bits(5);
            $sequence['operatingPoints'][] = ['idc' => 0, 'decoderModel' => false];
        } else {
            $bufferDelayLength = 0;

            if ($this->bits(1) === 1) {
                $this->bits(32);
                $this->bits(32);

                if ($sequence['equalPictureInterval'] = $this->bits(1) === 1) {
                    $this->uvlc();
                }

                if ($sequence['decoderModel'] = $this->bits(1) === 1) {
                    $bufferDelayLength = $this->bits(5) + 1;
                    $this->bits(32);
                    $sequence['bufferRemovalTimeLength'] = $this->bits(5) + 1;
                    $sequence['presentationTimeLength'] = $this->bits(5) + 1;
                }
            }

            $initialDisplayDelay = $this->bits(1) === 1;

            for ($count = $this->bits(5) + 1; $count > 0; $count--) {
                $idc = $this->bits(12);
                $decoderModel = false;

                if ($this->bits(5) > 7) {
                    $this->bits(1);
                }

                if ($sequence['decoderModel'] && $decoderModel = $this->bits(1) === 1) {
                    $this->bits(2 * $bufferDelayLength + 1);
                }

                if ($initialDisplayDelay && $this->bits(1) === 1) {
                    $this->bits(4);
                }

                // The decoder picks the first operating point.
                if ($sequence['operatingPoints'] === []) {
                    $sequence['operatingPointIdc'] = $idc;
                }

                $sequence['operatingPoints'][] = ['idc' => $idc, 'decoderModel' => $decoderModel];
            }
        }

        $sequence['frameWidthBits'] = $this->bits(4) + 1;
        $sequence['frameHeightBits'] = $this->bits(4) + 1;
        $sequence['maxFrameWidth'] = $this->bits($sequence['frameWidthBits']) + 1;
        $sequence['maxFrameHeight'] = $this->bits($sequence['frameHeightBits']) + 1;
        $sequence['frameIdLength'] = 0;
        $sequence['deltaFrameIdLength'] = 0;

        if (! $reduced && $this->bits(1) === 1) {
            $sequence['deltaFrameIdLength'] = $this->bits(4) + 2;
            $sequence['frameIdLength'] = $sequence['deltaFrameIdLength'] + $this->bits(3) + 1;
        }

        $sequence['use128x128Superblock'] = $this->bits(1) === 1;
        $this->bits(2);
        $sequence['enableWarpedMotion'] = false;
        $sequence['enableOrderHint'] = false;
        $sequence['enableRefFrameMvs'] = false;
        $sequence['forceScreenContentTools'] = self::SELECT;
        $sequence['forceIntegerMv'] = self::SELECT;
        $sequence['orderHintBits'] = 0;

        if (! $reduced) {
            $this->bits(2);
            $sequence['enableWarpedMotion'] = $this->bits(1) === 1;
            $this->bits(1);

            if ($sequence['enableOrderHint'] = $this->bits(1) === 1) {
                $this->bits(1);
                $sequence['enableRefFrameMvs'] = $this->bits(1) === 1;
            }

            $sequence['forceScreenContentTools'] = $this->bits(1) === 1 ? self::SELECT : $this->bits(1);

            if ($sequence['forceScreenContentTools'] > 0) {
                $sequence['forceIntegerMv'] = $this->bits(1) === 1 ? self::SELECT : $this->bits(1);
            }

            if ($sequence['enableOrderHint']) {
                $sequence['orderHintBits'] = $this->bits(3) + 1;
            }
        }

        $sequence['enableSuperres'] = $this->bits(1) === 1;
        $sequence['enableCdef'] = $this->bits(1) === 1;
        $sequence['enableRestoration'] = $this->bits(1) === 1;
        $sequence = [...$sequence, ...$this->colorConfig($profile)];
        $sequence['filmGrain'] = $this->bits(1) === 1;

        $this->sequence = $sequence;
    }

    /**
     * @return array{monochrome: bool, numPlanes: int, subsamplingX: int, subsamplingY: int, separateUvDeltaQ: bool}
     *
     * @throws InvalidMediaException
     */
    protected function colorConfig(int $profile): array
    {
        $twelveBit = $this->bits(1) === 1 && $profile === 2 && $this->bits(1) === 1;
        $monochrome = $profile !== 1 && $this->bits(1) === 1;
        [$primaries, $transfer, $matrix] = [2, 2, 2];

        if ($this->bits(1) === 1) {
            [$primaries, $transfer, $matrix] = [$this->bits(8), $this->bits(8), $this->bits(8)];
        }

        if ($monochrome) {
            $this->bits(1);

            return ['monochrome' => true, 'numPlanes' => 1, 'subsamplingX' => 1, 'subsamplingY' => 1, 'separateUvDeltaQ' => false];
        }

        [$subsamplingX, $subsamplingY] = [0, 0];

        // sRGB (BT.709 primaries, sRGB transfer, identity matrix) is always full range 4:4:4.
        if ($primaries !== 1 || $transfer !== 13 || $matrix !== 0) {
            $this->bits(1);

            if ($profile === 0) {
                [$subsamplingX, $subsamplingY] = [1, 1];
            } elseif ($profile > 1 && $twelveBit) {
                $subsamplingX = $this->bits(1);
                $subsamplingY = $subsamplingX === 1 ? $this->bits(1) : 0;
            } elseif ($profile > 1) {
                [$subsamplingX, $subsamplingY] = [1, 0];
            }

            if ($subsamplingX === 1 && $subsamplingY === 1) {
                $this->bits(2);
            }
        }

        return ['monochrome' => false, 'numPlanes' => 3, 'subsamplingX' => $subsamplingX, 'subsamplingY' => $subsamplingY, 'separateUvDeltaQ' => $this->bits(1) === 1];
    }

    /**
     * @return Sequence
     *
     * @throws InvalidMediaException
     */
    protected function sequence(): array
    {
        return $this->sequence ?? throw InvalidMediaException::unreadableBitstream('av1');
    }

    /**
     * Read an uncompressed frame header up to its end, keeping the tile layout of the frame and
     * updating the reference frame slots the frame refreshes.
     *
     * @throws InvalidMediaException
     */
    protected function frameHeader(int $temporalId, int $spatialId): void
    {
        $sequence = $this->sequence();
        $timing = $sequence['decoderModel'] && ! $sequence['equalPictureInterval'];
        [$frameType, $showFrame, $showableFrame, $errorResilient] = [self::KEY_FRAME, true, false, true];

        if (! $sequence['reduced']) {
            if ($this->bits(1) === 1) {
                $this->showExistingFrame($this->bits(3), $timing);

                return;
            }

            $frameType = $this->bits(2);
            $showFrame = $this->bits(1) === 1;

            if ($showFrame && $timing) {
                $this->bits($sequence['presentationTimeLength']);
            }

            $showableFrame = $showFrame ? $frameType !== self::KEY_FRAME : $this->bits(1) === 1;
            $errorResilient = $frameType === self::SWITCH_FRAME || ($frameType === self::KEY_FRAME && $showFrame) || $this->bits(1) === 1;
        }

        $intra = $frameType === self::KEY_FRAME || $frameType === self::INTRA_ONLY_FRAME;

        if ($frameType === self::KEY_FRAME && $showFrame) {
            foreach ($this->slots as $index => $slot) {
                $this->slots[$index]['orderHint'] = 0;
            }
        }

        $disableCdfUpdate = $this->bits(1) === 1;
        $screenContentTools = $sequence['forceScreenContentTools'] === self::SELECT ? $this->bits(1) : $sequence['forceScreenContentTools'];
        $forceIntegerMv = $screenContentTools === 1 && ($sequence['forceIntegerMv'] === self::SELECT ? $this->bits(1) : $sequence['forceIntegerMv']) === 1;
        $forceIntegerMv = $forceIntegerMv || $intra;

        if ($sequence['frameIdLength'] > 0) {
            $this->bits($sequence['frameIdLength']);
        }

        $sizeOverride = $frameType === self::SWITCH_FRAME || (! $sequence['reduced'] && $this->bits(1) === 1);
        $orderHint = $this->bits($sequence['orderHintBits']);
        $primaryRefFrame = $intra || $errorResilient ? self::PRIMARY_REF_NONE : $this->bits(3);

        if ($sequence['decoderModel'] && $this->bits(1) === 1) {
            foreach ($sequence['operatingPoints'] as $operatingPoint) {
                $idc = $operatingPoint['idc'];

                if ($operatingPoint['decoderModel'] && ($idc === 0 || ((($idc >> $temporalId) & 1) === 1 && (($idc >> ($spatialId + 8)) & 1) === 1))) {
                    $this->bits($sequence['bufferRemovalTimeLength']);
                }
            }
        }

        $refreshFrameFlags = $frameType === self::SWITCH_FRAME || ($frameType === self::KEY_FRAME && $showFrame) ? self::ALL_FRAMES : $this->bits(8);

        if ((! $intra || $refreshFrameFlags !== self::ALL_FRAMES) && $errorResilient && $sequence['enableOrderHint']) {
            foreach ($this->slots as $index => $slot) {
                $this->slots[$index]['orderHint'] = $this->bits($sequence['orderHintBits']);
            }
        }

        [$allowIntrabc, $allowHighPrecisionMv, $refFrameIdx] = [false, false, array_fill(0, 7, 0)];

        if ($intra) {
            [$upscaledWidth, $frameWidth, $frameHeight] = $this->frameSize($sizeOverride);
            $this->renderSize();
            $allowIntrabc = $screenContentTools === 1 && $upscaledWidth === $frameWidth && $this->bits(1) === 1;
        } else {
            if ($sequence['enableOrderHint'] && $this->bits(1) === 1) {
                $refFrameIdx = $this->frameRefs($this->bits(3), $this->bits(3), $orderHint);
            } else {
                $refFrameIdx = [];
            }

            for ($i = 0; $i < 7; $i++) {
                if (count($refFrameIdx) < 7) {
                    $refFrameIdx[] = $this->bits(3);
                }

                if ($sequence['deltaFrameIdLength'] > 0) {
                    $this->bits($sequence['deltaFrameIdLength']);
                }
            }

            if ($sizeOverride && ! $errorResilient) {
                [$upscaledWidth, $frameWidth, $frameHeight] = $this->frameSizeWithRefs($refFrameIdx);
            } else {
                [$upscaledWidth, $frameWidth, $frameHeight] = $this->frameSize($sizeOverride);
                $this->renderSize();
            }

            $allowHighPrecisionMv = ! $forceIntegerMv && $this->bits(1) === 1;

            if ($this->bits(1) === 0) {
                $this->bits(2);
            }

            $this->bits(1);

            if (! $errorResilient && $sequence['enableRefFrameMvs']) {
                $this->bits(1);
            }
        }

        if (! $sequence['reduced'] && ! $disableCdfUpdate) {
            $this->bits(1);
        }

        $previous = $primaryRefFrame === self::PRIMARY_REF_NONE ? null : $this->slots[$refFrameIdx[$primaryRefFrame]];
        $tiles = $this->tileInfo($frameWidth, $frameHeight);
        [$baseQIndex, $deltaQZero] = $this->quantizationParams();
        [$segmentationEnabled, $featureEnabled, $featureData] = $this->segmentationParams($primaryRefFrame, $previous);

        if ($baseQIndex > 0 && $this->bits(1) === 1) {
            $this->bits(2);

            if (! $allowIntrabc && $this->bits(1) === 1) {
                $this->bits(3);
            }
        }

        $codedLossless = true;

        for ($segment = 0; $segment < 8; $segment++) {
            $qIndex = $segmentationEnabled && $featureEnabled[$segment][0] ? max(0, min(255, $baseQIndex + $featureData[$segment][0])) : $baseQIndex;
            $codedLossless = $codedLossless && $qIndex === 0 && $deltaQZero;
        }

        $this->loopFilterParams($codedLossless || $allowIntrabc);
        $this->cdefParams($codedLossless || $allowIntrabc || ! $sequence['enableCdef']);
        $this->loopRestorationParams(($codedLossless && $frameWidth === $upscaledWidth) || $allowIntrabc || ! $sequence['enableRestoration']);

        if (! $codedLossless) {
            $this->bits(1);
        }

        $referenceSelect = ! $intra && $this->bits(1) === 1;

        if ($referenceSelect && $sequence['enableOrderHint'] && $this->skipModeAllowed($refFrameIdx, $orderHint)) {
            $this->bits(1);
        }

        if (! $intra && ! $errorResilient && $sequence['enableWarpedMotion']) {
            $this->bits(1);
        }

        $this->bits(1);

        if (! $intra) {
            $this->globalMotionParams($allowHighPrecisionMv);
        }

        $this->filmGrainParams($frameType, $showFrame || $showableFrame);

        $this->frame = $tiles;
        $this->refresh($refreshFrameFlags, $this->slot($frameType, $upscaledWidth, $frameHeight, $orderHint, $featureEnabled, $featureData));
    }

    /**
     * @throws InvalidMediaException
     */
    protected function showExistingFrame(int $index, bool $timing): void
    {
        $sequence = $this->sequence();

        if ($timing) {
            $this->bits($sequence['presentationTimeLength']);
        }

        if ($sequence['frameIdLength'] > 0) {
            $this->bits($sequence['frameIdLength']);
        }

        // Showing a key frame loads it and refreshes every slot with it.
        if ($this->slots[$index]['frameType'] === self::KEY_FRAME) {
            $this->refresh(self::ALL_FRAMES, $this->slots[$index]);
        }

        $this->frame = null;
    }

    /**
     * @param  array{frameType: int, upscaledWidth: int, frameHeight: int, orderHint: int, featureEnabled: list<list<bool>>, featureData: list<list<int>>}  $slot
     */
    protected function refresh(int $flags, array $slot): void
    {
        for ($index = 0; $index < 8; $index++) {
            if ((($flags >> $index) & 1) === 1) {
                $this->slots[$index] = $slot;
            }
        }
    }

    /**
     * @param  list<list<bool>>|null  $featureEnabled
     * @param  list<list<int>>|null  $featureData
     * @return array{frameType: int, upscaledWidth: int, frameHeight: int, orderHint: int, featureEnabled: list<list<bool>>, featureData: list<list<int>>}
     */
    protected function slot(int $frameType, int $upscaledWidth, int $frameHeight, int $orderHint, ?array $featureEnabled = null, ?array $featureData = null): array
    {
        return [
            'frameType' => $frameType,
            'upscaledWidth' => $upscaledWidth,
            'frameHeight' => $frameHeight,
            'orderHint' => $orderHint,
            'featureEnabled' => $featureEnabled ?? array_fill(0, 8, array_fill(0, 8, false)),
            'featureData' => $featureData ?? array_fill(0, 8, array_fill(0, 8, 0)),
        ];
    }

    /**
     * @return array{int, int, int} The upscaled width, and the coded width and height.
     *
     * @throws InvalidMediaException
     */
    protected function frameSize(bool $override): array
    {
        $sequence = $this->sequence();
        [$width, $height] = [$sequence['maxFrameWidth'], $sequence['maxFrameHeight']];

        if ($override) {
            [$width, $height] = [$this->bits($sequence['frameWidthBits']) + 1, $this->bits($sequence['frameHeightBits']) + 1];
        }

        return [$width, $this->superresWidth($width), $height];
    }

    /**
     * @throws InvalidMediaException
     */
    protected function superresWidth(int $upscaledWidth): int
    {
        if (! $this->sequence()['enableSuperres'] || $this->bits(1) === 0) {
            return $upscaledWidth;
        }

        $denominator = $this->bits(3) + 9;

        return intdiv($upscaledWidth * 8 + intdiv($denominator, 2), $denominator);
    }

    /**
     * @throws InvalidMediaException
     */
    protected function renderSize(): void
    {
        if ($this->bits(1) === 1) {
            $this->bits(32);
        }
    }

    /**
     * @param  list<int>  $refFrameIdx
     * @return array{int, int, int}
     *
     * @throws InvalidMediaException
     */
    protected function frameSizeWithRefs(array $refFrameIdx): array
    {
        foreach ($refFrameIdx as $index) {
            if ($this->bits(1) === 1) {
                $slot = $this->slots[$index];

                return [$slot['upscaledWidth'], $this->superresWidth($slot['upscaledWidth']), $slot['frameHeight']];
            }
        }

        $size = $this->frameSize(true);
        $this->renderSize();

        return $size;
    }

    /**
     * The reference frames of a frame that signals only its last and golden frame (section 7.8).
     *
     * @return list<int>
     */
    protected function frameRefs(int $lastFrameIdx, int $goldFrameIdx, int $orderHint): array
    {
        $bits = $this->sequence()['orderHintBits'];
        $current = 1 << ($bits - 1);
        $hints = array_map(fn (array $slot): int => $current + $this->relativeDistance($slot['orderHint'], $orderHint), $this->slots);
        $refs = array_fill(0, 7, -1);
        [$refs[0], $refs[3]] = [$lastFrameIdx, $goldFrameIdx];
        $used = array_fill(0, 8, false);
        [$used[$lastFrameIdx], $used[$goldFrameIdx]] = [true, true];

        $find = function (bool $backward, bool $latest) use ($hints, $current, &$used): int {
            $found = -1;

            foreach ($hints as $index => $hint) {
                if ($used[$index] || ($hint >= $current) !== $backward) {
                    continue;
                }

                if ($found < 0 || ($latest ? $hint >= $hints[$found] : $hint < $hints[$found])) {
                    $found = $index;
                }
            }

            if ($found >= 0) {
                $used[$found] = true;
            }

            return $found;
        };

        // ALTREF, BWDREF and ALTREF2, then LAST2, LAST3, BWDREF, ALTREF2 and ALTREF from the closest frames before.
        foreach ([6 => [true, true], 4 => [true, false], 5 => [true, false]] as $ref => [$backward, $latest]) {
            if (($index = $find($backward, $latest)) >= 0) {
                $refs[$ref] = $index;
            }
        }

        foreach ([1, 2, 4, 5, 6] as $ref) {
            if ($refs[$ref] < 0 && ($index = $find(false, true)) >= 0) {
                $refs[$ref] = $index;
            }
        }

        $earliest = 0;

        foreach ($hints as $index => $hint) {
            if ($hint < $hints[$earliest]) {
                $earliest = $index;
            }
        }

        return array_map(fn (int $ref): int => $ref < 0 ? $earliest : $ref, $refs);
    }

    protected function relativeDistance(int $a, int $b): int
    {
        $sequence = $this->sequence();

        if (! $sequence['enableOrderHint']) {
            return 0;
        }

        $difference = $a - $b;
        $middle = 1 << ($sequence['orderHintBits'] - 1);

        return ($difference & ($middle - 1)) - ($difference & $middle);
    }

    /**
     * @return array{tileCols: int, tileRows: int, tileBits: int, tileSizeBytes: int}
     *
     * @throws InvalidMediaException
     */
    protected function tileInfo(int $frameWidth, int $frameHeight): array
    {
        $superblock128 = $this->sequence()['use128x128Superblock'];
        $shift = $superblock128 ? 5 : 4;
        $miCols = 2 * (($frameWidth + 7) >> 3);
        $miRows = 2 * (($frameHeight + 7) >> 3);
        $sbCols = ($miCols + (1 << $shift) - 1) >> $shift;
        $sbRows = ($miRows + (1 << $shift) - 1) >> $shift;
        $sbSize = $shift + 2;
        $maxTileWidthSb = 4096 >> $sbSize;
        $maxTileAreaSb = (4096 * 2304) >> (2 * $sbSize);
        $minLog2TileCols = $this->tileLog2($maxTileWidthSb, $sbCols);
        $maxLog2TileCols = $this->tileLog2(1, min($sbCols, 64));
        $maxLog2TileRows = $this->tileLog2(1, min($sbRows, 64));
        $minLog2Tiles = max($minLog2TileCols, $this->tileLog2($maxTileAreaSb, $sbRows * $sbCols));

        if ($this->bits(1) === 1) {
            for ($colsLog2 = $minLog2TileCols; $colsLog2 < $maxLog2TileCols && $this->bits(1) === 1; $colsLog2++);

            $tileWidthSb = ($sbCols + (1 << $colsLog2) - 1) >> $colsLog2;
            $tileCols = intdiv($sbCols + $tileWidthSb - 1, $tileWidthSb);

            for ($rowsLog2 = max($minLog2Tiles - $colsLog2, 0); $rowsLog2 < $maxLog2TileRows && $this->bits(1) === 1; $rowsLog2++);

            $tileHeightSb = ($sbRows + (1 << $rowsLog2) - 1) >> $rowsLog2;
            $tileRows = intdiv($sbRows + $tileHeightSb - 1, $tileHeightSb);
        } else {
            [$widestTileSb, $tileCols] = [0, 0];

            for ($start = 0; $start < $sbCols; $start += $size, $tileCols++) {
                $size = $this->nonSymmetric(min($sbCols - $start, $maxTileWidthSb)) + 1;
                $widestTileSb = max($widestTileSb, $size);
            }

            $maxTileAreaSb = $minLog2Tiles > 0 ? ($sbRows * $sbCols) >> ($minLog2Tiles + 1) : $sbRows * $sbCols;
            $maxTileHeightSb = max(intdiv($maxTileAreaSb, $widestTileSb), 1);
            $tileRows = 0;

            for ($start = 0; $start < $sbRows; $start += $size, $tileRows++) {
                $size = $this->nonSymmetric(min($sbRows - $start, $maxTileHeightSb)) + 1;
            }

            $colsLog2 = $this->tileLog2(1, $tileCols);
            $rowsLog2 = $this->tileLog2(1, $tileRows);
        }

        $tileSizeBytes = 4;

        if ($colsLog2 > 0 || $rowsLog2 > 0) {
            $this->bits($rowsLog2 + $colsLog2);
            $tileSizeBytes = $this->bits(2) + 1;
        }

        return ['tileCols' => $tileCols, 'tileRows' => $tileRows, 'tileBits' => $colsLog2 + $rowsLog2, 'tileSizeBytes' => $tileSizeBytes];
    }

    protected function tileLog2(int $blockSize, int $target): int
    {
        for ($k = 0; ($blockSize << $k) < $target; $k++);

        return $k;
    }

    /**
     * @return array{int, bool} The base quantizer index, and whether every delta is zero.
     *
     * @throws InvalidMediaException
     */
    protected function quantizationParams(): array
    {
        $sequence = $this->sequence();
        $baseQIndex = $this->bits(8);
        $deltas = [$this->deltaQ()];

        if ($sequence['numPlanes'] > 1) {
            $differentUv = $sequence['separateUvDeltaQ'] && $this->bits(1) === 1;
            $deltas = [...$deltas, $this->deltaQ(), $this->deltaQ()];

            if ($differentUv) {
                $deltas = [...$deltas, $this->deltaQ(), $this->deltaQ()];
            }
        }

        if ($this->bits(1) === 1) {
            $this->bits($sequence['separateUvDeltaQ'] ? 12 : 8);
        }

        return [$baseQIndex, array_filter($deltas) === []];
    }

    /**
     * @throws InvalidMediaException
     */
    protected function deltaQ(): int
    {
        return $this->bits(1) === 1 ? $this->signed(7) : 0;
    }

    /**
     * @param  array{featureEnabled: list<list<bool>>, featureData: list<list<int>>}|null  $previous
     * @return array{bool, list<list<bool>>, list<list<int>>}
     *
     * @throws InvalidMediaException
     */
    protected function segmentationParams(int $primaryRefFrame, ?array $previous): array
    {
        $empty = $this->slot(self::KEY_FRAME, 0, 0, 0);

        if ($this->bits(1) === 0) {
            return [false, $empty['featureEnabled'], $empty['featureData']];
        }

        $updateData = true;

        if ($primaryRefFrame !== self::PRIMARY_REF_NONE) {
            if ($this->bits(1) === 1) {
                $this->bits(1);
            }

            $updateData = $this->bits(1) === 1;
        }

        if (! $updateData) {
            return [true, ($previous ?? $empty)['featureEnabled'], ($previous ?? $empty)['featureData']];
        }

        [$enabled, $data] = [[], []];

        for ($segment = 0; $segment < 8; $segment++) {
            [$segmentEnabled, $segmentData] = [[], []];

            for ($feature = 0; $feature < 8; $feature++) {
                $bits = self::SEGMENTATION_FEATURE_BITS[$feature];
                $limit = self::SEGMENTATION_FEATURE_MAX[$feature];
                $segmentEnabled[] = $featureEnabled = $this->bits(1) === 1;
                $segmentData[] = match (true) {
                    ! $featureEnabled => 0,
                    self::SEGMENTATION_FEATURE_SIGNED[$feature] => max(-$limit, min($limit, $this->signed(1 + $bits))),
                    default => min($limit, $this->bits($bits)),
                };
            }

            [$enabled[], $data[]] = [$segmentEnabled, $segmentData];
        }

        return [true, $enabled, $data];
    }

    /**
     * @throws InvalidMediaException
     */
    protected function loopFilterParams(bool $skip): void
    {
        if ($skip) {
            return;
        }

        $levels = [$this->bits(6), $this->bits(6)];

        if ($this->sequence()['numPlanes'] > 1 && ($levels[0] > 0 || $levels[1] > 0)) {
            $this->bits(12);
        }

        $this->bits(3);

        if ($this->bits(1) === 1 && $this->bits(1) === 1) {
            for ($i = 0; $i < 10; $i++) {
                if ($this->bits(1) === 1) {
                    $this->bits(7);
                }
            }
        }
    }

    /**
     * @throws InvalidMediaException
     */
    protected function cdefParams(bool $skip): void
    {
        if ($skip) {
            return;
        }

        $this->bits(2);
        $strengths = 1 << $this->bits(2);

        $this->bits($strengths * ($this->sequence()['numPlanes'] > 1 ? 12 : 6));
    }

    /**
     * @throws InvalidMediaException
     */
    protected function loopRestorationParams(bool $skip): void
    {
        if ($skip) {
            return;
        }

        $sequence = $this->sequence();
        [$usesLr, $usesChromaLr] = [false, false];

        for ($plane = 0; $plane < $sequence['numPlanes']; $plane++) {
            if ($this->bits(2) !== 0) {
                $usesLr = true;
                $usesChromaLr = $usesChromaLr || $plane > 0;
            }
        }

        if (! $usesLr) {
            return;
        }

        if ($this->bits(1) === 1 && ! $sequence['use128x128Superblock']) {
            $this->bits(1);
        }

        if ($sequence['subsamplingX'] === 1 && $sequence['subsamplingY'] === 1 && $usesChromaLr) {
            $this->bits(1);
        }
    }

    /**
     * Whether the frame has a forward and a second forward or backward reference for skip mode.
     *
     * @param  list<int>  $refFrameIdx
     */
    protected function skipModeAllowed(array $refFrameIdx, int $orderHint): bool
    {
        [$forward, $backward, $secondForward] = [null, null, null];
        $hints = array_map(fn (int $index): int => $this->slots[$index]['orderHint'], $refFrameIdx);

        foreach ($hints as $hint) {
            $distance = $this->relativeDistance($hint, $orderHint);

            if ($distance < 0 && ($forward === null || $this->relativeDistance($hint, $forward) > 0)) {
                $forward = $hint;
            } elseif ($distance > 0 && ($backward === null || $this->relativeDistance($hint, $backward) < 0)) {
                $backward = $hint;
            }
        }

        if ($forward === null || $backward !== null) {
            return $forward !== null;
        }

        foreach ($hints as $hint) {
            if ($this->relativeDistance($hint, $forward) < 0 && ($secondForward === null || $this->relativeDistance($hint, $secondForward) > 0)) {
                $secondForward = $hint;
            }
        }

        return $secondForward !== null;
    }

    /**
     * @throws InvalidMediaException
     */
    protected function globalMotionParams(bool $allowHighPrecisionMv): void
    {
        for ($ref = 1; $ref <= 7; $ref++) {
            $type = 0;

            if ($this->bits(1) === 1) {
                $type = $this->bits(1) === 1 ? 2 : ($this->bits(1) === 1 ? 1 : 3);
            }

            if ($type >= 2) {
                $params = $type === 3 ? 4 : 2;

                for ($i = 0; $i < $params; $i++) {
                    $this->subexponential((1 << 12) * 2 + 1);
                }
            }

            if ($type >= 1) {
                $absBits = $type === 1 ? 9 - ($allowHighPrecisionMv ? 0 : 1) : 12;
                $this->subexponential((1 << $absBits) * 2 + 1);
                $this->subexponential((1 << $absBits) * 2 + 1);
            }
        }
    }

    /**
     * @throws InvalidMediaException
     */
    protected function subexponential(int $symbols): void
    {
        for ($i = 0, $mk = 0, $k = 3; ; $i++) {
            $bits = $i > 0 ? $k + $i - 1 : $k;
            $a = 1 << $bits;

            if ($symbols <= $mk + 3 * $a) {
                $this->nonSymmetric($symbols - $mk);

                return;
            }

            if ($this->bits(1) === 0) {
                $this->bits($bits);

                return;
            }

            $mk += $a;
        }
    }

    /**
     * @throws InvalidMediaException
     */
    protected function filmGrainParams(int $frameType, bool $shown): void
    {
        $sequence = $this->sequence();

        if (! $sequence['filmGrain'] || ! $shown || $this->bits(1) === 0) {
            return;
        }

        $this->bits(16);

        if ($frameType === self::INTER_FRAME && $this->bits(1) === 0) {
            $this->bits(3);

            return;
        }

        $monochrome = $sequence['monochrome'];
        $yPoints = $this->bits(4);
        $this->bits(16 * $yPoints);
        $chromaFromLuma = ! $monochrome && $this->bits(1) === 1;
        [$cbPoints, $crPoints] = [0, 0];

        if (! $monochrome && ! $chromaFromLuma && ! ($sequence['subsamplingX'] === 1 && $sequence['subsamplingY'] === 1 && $yPoints === 0)) {
            $cbPoints = $this->bits(4);
            $this->bits(16 * $cbPoints);
            $crPoints = $this->bits(4);
            $this->bits(16 * $crPoints);
        }

        $this->bits(2);
        $lag = $this->bits(2);
        $lumaPositions = 2 * $lag * ($lag + 1);
        $chromaPositions = $lumaPositions + ($yPoints > 0 ? 1 : 0);
        $this->bits(8 * ($yPoints > 0 ? $lumaPositions : 0));
        $this->bits(8 * ($chromaFromLuma || $cbPoints > 0 ? $chromaPositions : 0));
        $this->bits(8 * ($chromaFromLuma || $crPoints > 0 ? $chromaPositions : 0));
        $this->bits(4);
        $this->bits($cbPoints > 0 ? 25 : 0);
        $this->bits($crPoints > 0 ? 25 : 0);
        $this->bits(2);
    }

    protected function read(string $data): void
    {
        $this->data = $data;
        $this->bit = 0;
    }

    /**
     * Read an unsigned number of bits, most significant first. Runs longer than 32 bits are skipped,
     * returning their last 32 bits.
     *
     * @phpstan-impure
     *
     * @throws InvalidMediaException
     */
    protected function bits(int $count): int
    {
        if ($count > 32) {
            for ($value = 0; $count > 0; $count -= $piece) {
                $piece = min($count, 32);
                $value = $this->bits($piece);
            }

            return $value;
        }

        if ($this->bit + $count > strlen($this->data) * 8) {
            throw InvalidMediaException::unreadableBitstream('av1');
        }

        $value = 0;

        for ($i = 0; $i < $count; $i++, $this->bit++) {
            $value = ($value << 1) | ((ord($this->data[$this->bit >> 3]) >> (7 - ($this->bit & 7))) & 1);
        }

        return $value;
    }

    /**
     * @phpstan-impure
     *
     * @throws InvalidMediaException
     */
    protected function signed(int $count): int
    {
        $value = $this->bits($count);
        $sign = 1 << ($count - 1);

        return ($value & $sign) !== 0 ? $value - 2 * $sign : $value;
    }

    /**
     * A number below $count in the fewest bits: ns(n) in the specification.
     *
     * @phpstan-impure
     *
     * @throws InvalidMediaException
     */
    protected function nonSymmetric(int $count): int
    {
        for ($width = 0, $n = $count; $n > 0; $n >>= 1) {
            $width++;
        }

        $m = (1 << $width) - $count;
        $value = $this->bits($width - 1);

        return $value < $m ? $value : ($value << 1) - $m + $this->bits(1);
    }

    /**
     * @phpstan-impure
     *
     * @throws InvalidMediaException
     */
    protected function uvlc(): int
    {
        for ($leadingZeros = 0; $this->bits(1) === 0; $leadingZeros++) {
            if ($leadingZeros >= 32) {
                throw InvalidMediaException::unreadableBitstream('av1');
            }
        }

        return $this->bits($leadingZeros) + (1 << $leadingZeros) - 1;
    }

    protected function byteAlign(): void
    {
        $this->bit = ($this->bit + 7) & ~7;
    }

    /**
     * @phpstan-impure
     *
     * @throws InvalidMediaException
     */
    protected function leb128(string $data, int &$position): int
    {
        $value = 0;

        for ($i = 0; $i < 8; $i++) {
            $byte = ord($data[$position++] ?? throw InvalidMediaException::unreadableBitstream('av1'));
            $value |= ($byte & 0x7F) << ($i * 7);

            if (($byte & 0x80) === 0) {
                return $value;
            }
        }

        throw InvalidMediaException::unreadableBitstream('av1');
    }
}
