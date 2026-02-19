package org.freegle.app.android.ui.screens

import androidx.compose.animation.core.*
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil3.compose.AsyncImage
import kotlinx.coroutines.launch
import org.freegle.app.android.ui.components.cleanTitle
import org.freegle.app.android.ui.components.formatTimeAgo
import org.freegle.app.model.MessageSummary
import org.freegle.app.repository.MessageRepository
import org.koin.compose.koinInject

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PostDetailScreen(
    messageId: Long,
    onBack: () -> Unit,
    onChatClick: (Long) -> Unit,
    messageRepository: MessageRepository = koinInject(),
) {
    var message by remember { mutableStateOf<MessageSummary?>(null) }
    var isLoading by remember { mutableStateOf(true) }
    var showInterestSent by remember { mutableStateOf(false) }

    LaunchedEffect(messageId) {
        isLoading = true
        message = messageRepository.getMessageDetail(messageId)
        isLoading = false
    }

    if (isLoading) {
        Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            CircularProgressIndicator()
        }
        return
    }

    if (message == null) {
        Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Text("Item not found", style = MaterialTheme.typography.bodyLarge)
                Spacer(Modifier.height(8.dp))
                Button(onClick = onBack) { Text("Go back") }
            }
        }
        return
    }

    val msg = message!!
    val scaffoldState = rememberBottomSheetScaffoldState(
        bottomSheetState = rememberStandardBottomSheetState(
            initialValue = SheetValue.PartiallyExpanded,
            skipHiddenState = true,
        ),
    )

    BottomSheetScaffold(
        scaffoldState = scaffoldState,
        sheetPeekHeight = 230.dp,
        sheetShape = RoundedCornerShape(topStart = 24.dp, topEnd = 24.dp),
        sheetContainerColor = MaterialTheme.colorScheme.surface,
        sheetDragHandle = null,  // We render our own handle in ItemDetailSheet
        topBar = null,
        sheetContent = {
            ItemDetailSheet(
                message = msg,
                showInterestSent = showInterestSent,
                onInterested = { showInterestSent = true },
            )
        },
        containerColor = Color.Black,
    ) { _ ->
        // Full-screen photo behind the sheet
        Box(modifier = Modifier.fillMaxSize()) {
            PhotoBackground(message = msg)

            // Floating back button
            IconButton(
                onClick = onBack,
                modifier = Modifier
                    .padding(8.dp)
                    .background(Color.Black.copy(alpha = 0.35f), CircleShape),
            ) {
                Icon(
                    Icons.AutoMirrored.Filled.ArrowBack,
                    contentDescription = "Back",
                    tint = Color.White,
                )
            }
        }
    }
}

@Composable
private fun PhotoBackground(message: MessageSummary) {
    val attachments = message.messageAttachments ?: emptyList()
    val title = cleanTitle(message.subject ?: "Item")
    val isOffer = message.type == "Offer"

    if (attachments.isNotEmpty()) {
        val pagerState = rememberPagerState(pageCount = { attachments.size })

        Box(modifier = Modifier.fillMaxSize()) {
            HorizontalPager(
                state = pagerState,
                modifier = Modifier.fillMaxSize(),
            ) { page ->
                // Parallax effect: image slides slightly with page offset
                val pageOffset = (pagerState.currentPage - page) + pagerState.currentPageOffsetFraction
                AsyncImage(
                    model = attachments[page].path,
                    contentDescription = title,
                    modifier = Modifier
                        .fillMaxSize()
                        .graphicsLayer {
                            translationX = pageOffset * size.width * 0.3f
                        },
                    contentScale = ContentScale.Crop,
                )
            }

            // Page indicator
            if (attachments.size > 1) {
                Row(
                    modifier = Modifier
                        .align(Alignment.BottomCenter)
                        .padding(bottom = 240.dp),
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    repeat(attachments.size) { index ->
                        val isCurrent = pagerState.currentPage == index
                        Box(
                            modifier = Modifier
                                .size(
                                    width = if (isCurrent) 20.dp else 8.dp,
                                    height = 8.dp,
                                )
                                .clip(CircleShape)
                                .background(
                                    if (isCurrent) Color.White
                                    else Color.White.copy(alpha = 0.45f),
                                ),
                        )
                    }
                }
            }

            // Top gradient (for back button visibility)
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .height(120.dp)
                    .background(
                        Brush.verticalGradient(
                            colors = listOf(Color.Black.copy(alpha = 0.5f), Color.Transparent),
                        ),
                    ),
            )
        }
    } else {
        // No image - beautiful gradient with initial
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(
                    Brush.verticalGradient(
                        colors = if (isOffer)
                            listOf(Color(0xFF003318), Color(0xFF00B050))
                        else
                            listOf(Color(0xFF3D1800), Color(0xFFFF6B35)),
                    ),
                ),
            contentAlignment = Alignment.Center,
        ) {
            Text(
                text = title.take(2).uppercase(),
                style = MaterialTheme.typography.displayLarge,
                color = Color.White.copy(alpha = 0.2f),
                fontWeight = FontWeight.Black,
                fontSize = 96.sp,
            )
        }
    }
}

@Composable
private fun ItemDetailSheet(
    message: MessageSummary,
    showInterestSent: Boolean,
    onInterested: () -> Unit,
) {
    val title = cleanTitle(message.subject ?: "Item")
    val isOffer = message.type == "Offer"
    val location = message.location?.areaname
        ?: message.messageGroups?.firstOrNull()?.namedisplay
    val timeStr = message.arrival ?: message.date

    Column(
        modifier = Modifier
            .fillMaxWidth()
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 24.dp)
            .padding(bottom = 32.dp),
    ) {
        // Drag handle
        Box(
            modifier = Modifier
                .width(40.dp)
                .height(4.dp)
                .clip(CircleShape)
                .background(MaterialTheme.colorScheme.outlineVariant)
                .align(Alignment.CenterHorizontally),
        )

        Spacer(Modifier.height(16.dp))

        // Type badge
        Surface(
            shape = RoundedCornerShape(6.dp),
            color = if (isOffer) Color(0xFF00B050) else Color(0xFFFF6B35),
        ) {
            Text(
                text = if (isOffer) "FREE ITEM" else "WANTED",
                modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp),
                style = MaterialTheme.typography.labelMedium,
                color = Color.White,
                fontWeight = FontWeight.Bold,
            )
        }

        Spacer(Modifier.height(10.dp))

        // Title
        Text(
            text = title,
            style = MaterialTheme.typography.headlineMedium,
            fontWeight = FontWeight.Bold,
        )

        Spacer(Modifier.height(8.dp))

        // Location + time
        Row(
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            if (location != null) {
                Icon(
                    Icons.Default.LocationOn,
                    contentDescription = null,
                    modifier = Modifier.size(15.dp),
                    tint = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Text(
                    text = location,
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            if (timeStr != null) {
                Text(
                    text = "· ${formatTimeAgo(timeStr)}",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }

        // Interest sent confirmation
        if (showInterestSent) {
            Spacer(Modifier.height(16.dp))
            Card(
                colors = CardDefaults.cardColors(
                    containerColor = MaterialTheme.colorScheme.primaryContainer,
                ),
                shape = RoundedCornerShape(16.dp),
                modifier = Modifier.fillMaxWidth(),
            ) {
                Row(
                    modifier = Modifier.padding(16.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(
                        Icons.Default.CheckCircle,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.primary,
                        modifier = Modifier.size(22.dp),
                    )
                    Spacer(Modifier.width(10.dp))
                    Text(
                        "Message sent! You'll be notified when they reply.",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onPrimaryContainer,
                    )
                }
            }
        }

        Spacer(Modifier.height(20.dp))

        // Description
        if (!message.textbody.isNullOrBlank()) {
            Text(
                text = message.textbody!!,
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurface,
            )
            Spacer(Modifier.height(20.dp))
        }

        HorizontalDivider()
        Spacer(Modifier.height(12.dp))

        // Posted by
        Row(
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Box(
                modifier = Modifier
                    .size(36.dp)
                    .clip(CircleShape)
                    .background(MaterialTheme.colorScheme.secondaryContainer),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    Icons.Default.Person,
                    contentDescription = null,
                    modifier = Modifier.size(20.dp),
                    tint = MaterialTheme.colorScheme.onSecondaryContainer,
                )
            }
            Column {
                Text(
                    "Posted by a Freegler",
                    style = MaterialTheme.typography.bodyMedium,
                    fontWeight = FontWeight.Medium,
                )
                if (message.replycount > 0) {
                    Text(
                        "${message.replycount} ${if (message.replycount == 1) "reply" else "replies"}",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }

        Spacer(Modifier.height(24.dp))

        // CTA - the reach-out action
        if (!showInterestSent) {
            Button(
                onClick = onInterested,
                modifier = Modifier
                    .fillMaxWidth()
                    .height(56.dp),
                shape = RoundedCornerShape(18.dp),
                colors = ButtonDefaults.buttonColors(
                    containerColor = if (isOffer) Color(0xFF00B050) else Color(0xFFFF6B35),
                ),
            ) {
                Icon(
                    if (isOffer) Icons.Default.Favorite else Icons.Default.NotificationsActive,
                    contentDescription = null,
                    modifier = Modifier.size(22.dp),
                )
                Spacer(Modifier.width(10.dp))
                Text(
                    text = if (isOffer) "I'd love this!" else "I have one!",
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold,
                )
            }

            Spacer(Modifier.height(8.dp))

            Text(
                text = "A message will be sent on your behalf",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth(),
            )
        }
    }
}
