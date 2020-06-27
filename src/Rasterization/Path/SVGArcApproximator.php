<?php

namespace SVG\Rasterization\Path;

/**
 * This class can approximate elliptical arc segments by calculating a series of
 * points on them (converting them to polylines).
 */
class SVGArcApproximator
{
    private static $EPSILON = 0.0000001;

    /**
     * Approximates an elliptical arc segment given the start point, the end
     * point, the section to use (large or small), the sweep direction,
     * the ellipsis' radii, and its rotation.
     *
     * All of the points (input and output) are represented as float arrays
     * where [0 => x coordinate, 1 => y coordinate].
     *
     * @param float[] $start    The start point (x0, y0).
     * @param float[] $end      The end point (x1, y1).
     * @param bool    $large    The large arc flag.
     * @param bool    $sweep    The sweep direction flag.
     * @param float   $radiusX  The x radius.
     * @param float   $radiusY  The y radius.
     * @param float   $rotation The x-axis angle / the ellipse's rotation (radians).
     *
     * @return array[] An approximation for the curve, as an array of points.
     */
    public function approximate($start, $end, $large, $sweep, $radiusX, $radiusY, $rotation)
    {
        // out-of-range parameter handling according to W3; see
        // https://www.w3.org/TR/SVG11/implnote.html#ArcImplementationNotes
        if (self::pointsClose($start, $end)) {
            // arc with equal points is treated as nonexistent
            return array();
        }
        $radiusX = abs($radiusX);
        $radiusY = abs($radiusY);
        if ($radiusX < self::$EPSILON || $radiusY < self::$EPSILON) {
            // arc with no radius is treated as straight line
            return array($start, $end);
        }

        $cosr = cos($rotation);
        $sinr = sin($rotation);

        list($center, $angleStart, $angleDelta) = self::endpointToCenter(
            $start, $end, $large, $sweep, $radiusX, $radiusY, $cosr, $sinr);

        // TODO implement better calculation for $numSteps
        // It would be better if we had access to the rasterization scale for
        // this, otherwise there is no way to make this accurate for every zoom
        $dist = abs($end[0] - $start[0]) + abs($end[1] - $start[1]);
        $numSteps = max(2, ceil(abs($angleDelta * $dist)));
        $stepSize = $angleDelta / $numSteps;

        $points = array();

        for ($i = 0; $i <= $numSteps; ++$i) {
            $angle = $angleStart + $stepSize * $i;
            $first = $radiusX * cos($angle);
            $second = $radiusY * sin($angle);

            $points[] = array(
                $cosr * $first - $sinr * $second + $center[0],
                $sinr * $first + $cosr * $second + $center[1],
            );
        }

        return $points;
    }

    /**
     * Converts an ellipse in endpoint parameterization (standard for SVG paths)
     * to the corresponding center parameterization (easier to work with).
     *
     * In other words, takes two points, sweep flags, and size/orientation
     * values and computes from them the ellipse's optimal center point and the
     * angles the segment covers. For this, the start angle and the angle delta
     * are returned.
     *
     * The formulas can be found in W3's SVG spec.
     *
     * @see https://www.w3.org/TR/SVG11/implnote.html#ArcImplementationNotes
     *
     * @param float[] $start   The start point (x0, y0).
     * @param float[] $end     The end point (x1, y1).
     * @param bool    $large   The large arc flag.
     * @param bool    $sweep   The sweep direction flag.
     * @param float   $radiusX The x radius.
     * @param float   $radiusY The y radius.
     * @param float   $cosr    Cosine of the ellipsis rotation.
     * @param float   $sinr    Sine of the ellipsis rotation.
     *
     * @return float[] A tuple with (center (cx, cy), angleStart, angleDelta).
     */
    private static function endpointToCenter($start, $end, $large, $sweep, $radiusX, $radiusY, $cosr, $sinr)
    {
        // Step 1: Compute (x1', y1')
        $xsubhalf = ($start[0] - $end[0]) / 2;
        $ysubhalf = ($start[1] - $end[1]) / 2;
        $x1prime  = $cosr * $xsubhalf + $sinr * $ysubhalf;
        $y1prime  = -$sinr * $xsubhalf + $cosr * $ysubhalf;

        // TODO ensure radiuses are large enough

        // squares that occur multiple times
        $rx2 = $radiusX * $radiusX;
        $ry2 = $radiusY * $radiusY;
        $x1prime2 = $x1prime * $x1prime;
        $y1prime2 = $y1prime * $y1prime;

        // Step 2: Compute (cx', cy')
        $cxfactor = ($large != $sweep ? 1 : -1) * sqrt(abs(
            ($rx2*$ry2 - $rx2*$y1prime2 - $ry2*$x1prime2) / ($rx2*$y1prime2 + $ry2*$x1prime2)
        ));
        $cxprime = $cxfactor *  $radiusX * $y1prime / $radiusY;
        $cyprime = $cxfactor * -$radiusY * $x1prime / $radiusX;

        // Step 3: Compute (cx, cy) from (cx', cy')
        $centerX = $cosr * $cxprime - $sinr * $cyprime + ($start[0] + $end[0]) / 2;
        $centerY = $sinr * $cxprime + $cosr * $cyprime + ($start[1] + $end[1]) / 2;

        // Step 4: Compute the angles
        $angleStart = self::vectorAngle(
            ($x1prime - $cxprime) / $radiusX,
            ($y1prime - $cyprime) / $radiusY
        );
        $angleDelta = self::vectorAngle2(
            ( $x1prime - $cxprime) / $radiusX,
            ( $y1prime - $cyprime) / $radiusY,
            (-$x1prime - $cxprime) / $radiusX,
            (-$y1prime - $cyprime) / $radiusY
        );

        // Adapt angles to sweep flags
        if (!$sweep && $angleDelta > 0) {
            $angleDelta -= M_PI * 2;
        } elseif ($sweep && $angleDelta < 0) {
            $angleDelta += M_PI * 2;
        }

        return array(array($centerX, $centerY), $angleStart, $angleDelta);
    }

    /**
     * Computes the angle between a vector and the positive x axis.
     * This is a simplified version of vectorAngle2, where the first vector is
     * fixed as [1, 0].
     *
     * @param float $vecx The vector's x coordinate.
     * @param float $vecy The vector's y coordinate.
     *
     * @return float The angle, in radians.
     */
    private static function vectorAngle($vecx, $vecy)
    {
        $norm = sqrt($vecx * $vecx + $vecy * $vecy);
        return ($vecy >= 0 ? 1 : -1) * acos($vecx / $norm);
    }

    /**
     * Computes the angle between two given vectors.
     *
     * @param float $vec1x First vector's x coordinate.
     * @param float $vec1y First vector's y coordinate.
     * @param float $vec2x Second vector's x coordinate.
     * @param float $vec2y Second vector's y coordinate.
     *
     * @return float The angle, in radians.
     */
    private static function vectorAngle2($vec1x, $vec1y, $vec2x, $vec2y)
    {
        $dotprod = $vec1x * $vec2x + $vec1y * $vec2y;
        $norm = sqrt($vec1x * $vec1x + $vec1y * $vec1y) * sqrt($vec2x * $vec2x + $vec2y * $vec2y);

        $sign = ($vec1x * $vec2y - $vec1y * $vec2x) >= 0 ? 1 : -1;

        return $sign * acos($dotprod / $norm);
    }

    /**
     * Determine whether two points are basically the same, except for miniscule
     * differences.
     *
     * @param float[] $start The start point (x0, y0).
     * @param float[] $end   The end point (x1, y1).
     * @return bool Whether the points are close.
     */
    private static function pointsClose($vec1, $vec2)
    {
        $distanceX = abs($vec1[0] - $vec2[0]);
        $distanceY = abs($vec1[1] - $vec2[1]);

        return $distanceX < self::$EPSILON && $distanceY < self::$EPSILON;
    }
}
