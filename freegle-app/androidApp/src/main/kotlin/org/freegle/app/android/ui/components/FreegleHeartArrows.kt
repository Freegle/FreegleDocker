package org.freegle.app.android.ui.components

import androidx.compose.foundation.Canvas
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Matrix
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.withTransform
import androidx.compose.ui.graphics.vector.PathParser

/**
 * Draws the Freegle heart-shaped recycling arrows motif.
 *
 * Extracted from the Freegle SVG logo (user_logo_vector.svg) — just the two
 * curved arrows that form the iconic heart/recycling loop, without the green
 * background or "freegle" text. Scales to fit the available space while
 * maintaining the original aspect ratio.
 */
@Composable
fun FreegleHeartArrows(
    modifier: Modifier = Modifier,
    color: Color = Color.White,
) {
    val arrowData = remember { buildArrowPaths() }

    Canvas(modifier = modifier) {
        val bounds = arrowData.bounds
        val fitScale = minOf(size.width / bounds.width, size.height / bounds.height)
        val offsetX = (size.width - bounds.width * fitScale) / 2f
        val offsetY = (size.height - bounds.height * fitScale) / 2f

        withTransform({
            translate(offsetX, offsetY)
            scale(fitScale, fitScale, Offset.Zero)
            translate(-bounds.left, -bounds.top)
        }) {
            drawPath(arrowData.arrow1, color = color)
            drawPath(arrowData.arrow2, color = color)
        }
    }
}

private data class ArrowPaths(val arrow1: Path, val arrow2: Path, val bounds: Rect)

private fun buildArrowPaths(): ArrowPaths {
    // SVG path data for the two recycling arrows from user_logo_vector.svg.
    // Arrow 1 (top-right, sweeping clockwise) — origin translate(105.3594, 152.8521)
    val arrow1Data =
        "M 0,0 C 31.889,29.569 57.332,14.995 69.094,-4.978 " +
        "93.145,-45.822 54.478,-107.184 10.63,-126.229 " +
        "l 6.642,-11.958 -31.003,5.315 10.63,30.896 6.644,-13.623 " +
        "c 32.604,19.66 67.668,68.226 50.491,102.206 " +
        "C 46.392,1.726 22.588,10.63 0,0"

    // Arrow 2 (bottom-left, sweeping counter-clockwise) — origin translate(88.085, 20.7598)
    val arrow2Data =
        "m 0,0 c -26.162,4.981 -70.671,47.691 -76.18,96.111 " +
        "-5.581,49.045 29.74,66.915 62.007,46.948 " +
        "l 4.872,15.059 14.616,-30.232 -29.216,-12.362 4.856,14.69 " +
        "c -30.118,12.402 -44.48,1.072 -39.419,-33.66 " +
        "C -55.084,73.358 -23.109,6.882 0,0"

    val path1 = PathParser().parsePathString(arrow1Data).toPath()
    val path2 = PathParser().parsePathString(arrow2Data).toPath()

    // The SVG's root transform is matrix(1.25, 0, 0, -1.25, 0, 240.95).
    // Combined with each arrow's translate origin, the final screen mapping is:
    //   x' = 1.25 * (x + tx),  y' = -1.25 * (y + ty) + 240.95
    // which simplifies to  translate(1.25*tx, -1.25*ty + 240.95) then scale(1.25, -1.25).

    val m1 = Matrix().apply {
        translate(x = 1.25f * 105.3594f, y = -1.25f * 152.8521f + 240.95f)
        scale(x = 1.25f, y = -1.25f)
    }
    path1.transform(m1)

    val m2 = Matrix().apply {
        translate(x = 1.25f * 88.085f, y = -1.25f * 20.7598f + 240.95f)
        scale(x = 1.25f, y = -1.25f)
    }
    path2.transform(m2)

    val b1 = path1.getBounds()
    val b2 = path2.getBounds()
    val bounds = Rect(
        left = minOf(b1.left, b2.left),
        top = minOf(b1.top, b2.top),
        right = maxOf(b1.right, b2.right),
        bottom = maxOf(b1.bottom, b2.bottom),
    )

    return ArrowPaths(path1, path2, bounds)
}
