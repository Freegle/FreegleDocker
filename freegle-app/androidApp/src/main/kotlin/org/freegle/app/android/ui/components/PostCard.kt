package org.freegle.app.android.ui.components

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CardGiftcard
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import coil3.compose.AsyncImage
import org.freegle.app.model.MessageSummary

@Composable
fun PostCard(
    message: MessageSummary,
    onClick: () -> Unit,
) {
    val imageUrl = message.messageAttachments?.firstOrNull()?.paththumb
        ?: message.messageAttachments?.firstOrNull()?.path

    val title = cleanTitle(message.subject ?: "Item")
    val isOffer = message.type == "Offer"

    Card(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick),
        shape = MaterialTheme.shapes.medium,
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
    ) {
        Column {
            // Photo with overlay badge
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .height(160.dp),
            ) {
                if (imageUrl != null) {
                    AsyncImage(
                        model = imageUrl,
                        contentDescription = title,
                        modifier = Modifier
                            .fillMaxSize()
                            .clip(RoundedCornerShape(topStart = 12.dp, topEnd = 12.dp)),
                        contentScale = ContentScale.Crop,
                    )
                } else {
                    // Warm no-image placeholder with gift icon
                    Surface(
                        modifier = Modifier.fillMaxSize(),
                        color = if (isOffer) MaterialTheme.colorScheme.primaryContainer
                                else MaterialTheme.colorScheme.tertiaryContainer,
                        shape = RoundedCornerShape(topStart = 12.dp, topEnd = 12.dp),
                    ) {
                        Box(
                            modifier = Modifier.fillMaxSize(),
                            contentAlignment = Alignment.Center,
                        ) {
                            Icon(
                                Icons.Outlined.CardGiftcard,
                                contentDescription = null,
                                modifier = Modifier.size(48.dp),
                                tint = if (isOffer) MaterialTheme.colorScheme.onPrimaryContainer
                                       else MaterialTheme.colorScheme.onTertiaryContainer,
                            )
                        }
                    }
                }

                // FREE / WANTED overlay badge top-left
                Surface(
                    modifier = Modifier
                        .align(Alignment.TopStart)
                        .padding(8.dp),
                    shape = MaterialTheme.shapes.extraSmall,
                    color = if (isOffer) MaterialTheme.colorScheme.primary
                            else MaterialTheme.colorScheme.tertiary,
                ) {
                    Text(
                        text = if (isOffer) "FREE" else "WANTED",
                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 3.dp),
                        style = MaterialTheme.typography.labelSmall,
                        color = if (isOffer) MaterialTheme.colorScheme.onPrimary
                                else MaterialTheme.colorScheme.onTertiary,
                        fontWeight = FontWeight.Bold,
                    )
                }
            }

            // Text content
            Column(modifier = Modifier.padding(horizontal = 10.dp, vertical = 8.dp)) {
                Text(
                    text = title,
                    style = MaterialTheme.typography.titleSmall,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                    fontWeight = FontWeight.SemiBold,
                )

                Spacer(Modifier.height(4.dp))

                Row(
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.SpaceBetween,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    // Location
                    val areaName = message.location?.areaname
                        ?: message.messageGroups?.firstOrNull()?.namedisplay
                    if (areaName != null) {
                        Text(
                            text = areaName,
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                            modifier = Modifier.weight(1f, fill = false),
                        )
                    }

                    Spacer(Modifier.width(4.dp))

                    // Time ago - green if recent (within 24h), muted if older
                    val timeStr = message.arrival ?: message.date
                    if (timeStr != null) {
                        Text(
                            text = formatTimeAgo(timeStr),
                            style = MaterialTheme.typography.labelSmall,
                            color = if (isRecentPost(timeStr))
                                MaterialTheme.colorScheme.primary
                            else
                                MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.6f),
                        )
                    }
                }
            }
        }
    }
}

// Skeleton card for loading state - mirrors PostCard layout exactly
@Composable
fun PostCardSkeleton() {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = MaterialTheme.shapes.medium,
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
    ) {
        Column {
            // Image placeholder
            Surface(
                modifier = Modifier
                    .fillMaxWidth()
                    .height(160.dp),
                color = MaterialTheme.colorScheme.surfaceVariant,
                shape = RoundedCornerShape(topStart = 12.dp, topEnd = 12.dp),
            ) {}
            Column(modifier = Modifier.padding(horizontal = 10.dp, vertical = 8.dp)) {
                // Title lines
                Surface(
                    modifier = Modifier.fillMaxWidth().height(14.dp),
                    shape = MaterialTheme.shapes.extraSmall,
                    color = MaterialTheme.colorScheme.surfaceVariant,
                ) {}
                Spacer(Modifier.height(4.dp))
                Surface(
                    modifier = Modifier.fillMaxWidth(0.7f).height(14.dp),
                    shape = MaterialTheme.shapes.extraSmall,
                    color = MaterialTheme.colorScheme.surfaceVariant,
                ) {}
                Spacer(Modifier.height(6.dp))
                Surface(
                    modifier = Modifier.width(80.dp).height(12.dp),
                    shape = MaterialTheme.shapes.extraSmall,
                    color = MaterialTheme.colorScheme.surfaceVariant,
                ) {}
            }
        }
    }
}

fun cleanTitle(subject: String): String {
    return subject
        .removePrefix("OFFER: ")
        .removePrefix("WANTED: ")
        .replace(Regex("\\s*\\(.*\\)\\s*$"), "") // Remove trailing (Location)
        .trim()
}

fun formatTimeAgo(dateStr: String): String {
    return try {
        // Parse ISO 8601: "2026-02-19T21:34:24Z" or "2026-02-19 21:34:24"
        val cleaned = dateStr.replace("T", " ").replace("Z", "").trim()
        val parts = cleaned.split(" ")
        val dateParts = parts[0].split("-")
        val timeParts = if (parts.size > 1) parts[1].split(":") else listOf("0", "0", "0")

        val year = dateParts[0].toInt()
        val month = dateParts[1].toInt()
        val day = dateParts[2].toInt()
        val hour = timeParts[0].toInt()
        val minute = timeParts[1].toInt()

        // Approximate millis (UTC, ignoring leap seconds)
        var totalDays = 0L
        for (y in 1970 until year) {
            totalDays += if (y % 4 == 0 && (y % 100 != 0 || y % 400 == 0)) 366 else 365
        }
        val daysInMonth = intArrayOf(0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31)
        // Leap year adjustment
        if (year % 4 == 0 && (year % 100 != 0 || year % 400 == 0) && month > 2) totalDays++
        for (m in 1 until month) totalDays += daysInMonth[m]
        totalDays += day - 1

        val parsedMillis = totalDays * 86400000L + hour * 3600000L + minute * 60000L
        val diffMillis = System.currentTimeMillis() - parsedMillis

        when {
            diffMillis < 0 -> "just now"
            diffMillis < 60_000 -> "just now"
            diffMillis < 3_600_000 -> "${diffMillis / 60_000}m ago"
            diffMillis < 86_400_000 -> "${diffMillis / 3_600_000}h ago"
            diffMillis < 7 * 86_400_000L -> "${diffMillis / 86_400_000}d ago"
            else -> "${diffMillis / (7 * 86_400_000L)}w ago"
        }
    } catch (_: Exception) {
        ""
    }
}

fun isRecentPost(dateStr: String): Boolean {
    return try {
        val cleaned = dateStr.replace("T", " ").replace("Z", "").trim()
        val parts = cleaned.split(" ")
        val dateParts = parts[0].split("-")
        val timeParts = if (parts.size > 1) parts[1].split(":") else listOf("0", "0", "0")
        val year = dateParts[0].toInt()
        val month = dateParts[1].toInt()
        val day = dateParts[2].toInt()
        val hour = timeParts[0].toInt()
        val minute = timeParts[1].toInt()
        var totalDays = 0L
        for (y in 1970 until year) {
            totalDays += if (y % 4 == 0 && (y % 100 != 0 || y % 400 == 0)) 366 else 365
        }
        val daysInMonth = intArrayOf(0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31)
        if (year % 4 == 0 && (year % 100 != 0 || year % 400 == 0) && month > 2) totalDays++
        for (m in 1 until month) totalDays += daysInMonth[m]
        totalDays += day - 1
        val parsedMillis = totalDays * 86400000L + hour * 3600000L + minute * 60000L
        System.currentTimeMillis() - parsedMillis < 86_400_000L
    } catch (_: Exception) {
        false
    }
}
