package org.freegle.app.android.ui.components

import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Favorite
import androidx.compose.material3.Icon
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp

/**
 * Freegle "Give" button icon — a heart symbolising generosity and community giving.
 * Uses Material Favorite icon for crisp rendering at all sizes.
 */
@Composable
fun FreegleHeartArrows(
    modifier: Modifier = Modifier,
    color: Color = Color.White,
) {
    Icon(
        imageVector = Icons.Filled.Favorite,
        contentDescription = "Give",
        modifier = modifier,
        tint = color,
    )
}
