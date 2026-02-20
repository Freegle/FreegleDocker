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
import androidx.compose.material.icons.automirrored.filled.Send
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
import org.freegle.app.api.FreegleApi
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
    api: FreegleApi = koinInject(),
) {
    var message by remember { mutableStateOf<MessageSummary?>(null) }
    var isLoading by remember { mutableStateOf(true) }
    var showInterestSent by remember { mutableStateOf(false) }
    var isSendingInterest by remember { mutableStateOf(false) }
    var sendError by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

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
                isSending = isSendingInterest,
                errorMessage = sendError,
                onSendMessage = { userMessage ->
                    if (!isSendingInterest) {
                        isSendingInterest = true
                        sendError = null
                        scope.launch {
                            try {
                                val success = api.replyToMessage(messageId, userMessage)
                                if (success) {
                                    showInterestSent = true
                                } else {
                                    sendError = "Couldn\u2019t send your message. Please try again."
                                }
                            } catch (_: Exception) {
                                sendError = "No connection. Check your internet and try again."
                            }
                            isSendingInterest = false
                        }
                    }
                },
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
                            listOf(Color(0xFF003318), Color(0xFF008040))
                        else
                            listOf(Color(0xFF0D2B4D), Color(0xFF1565C0)),
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
    isSending: Boolean = false,
    errorMessage: String? = null,
    onSendMessage: (String) -> Unit,
) {
    val title = cleanTitle(message.subject ?: "Item")
    val isOffer = message.type == "Offer"
    val location = message.location?.areaname
        ?: message.messageGroups?.firstOrNull()?.namedisplay
    val timeStr = message.arrival ?: message.date
    var userMessage by remember { mutableStateOf("") }

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
            color = if (isOffer) Color(0xFF008040) else Color(0xFF1565C0),
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
                    text = "\u00b7 ${formatTimeAgo(timeStr)}",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }

        // Message sent confirmation
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
                        "Message sent! You\u2019ll be notified when they reply.",
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

        Spacer(Modifier.height(20.dp))

        // Error message
        if (errorMessage != null) {
            Spacer(Modifier.height(8.dp))
            Card(
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.errorContainer),
                shape = RoundedCornerShape(12.dp),
                modifier = Modifier.fillMaxWidth(),
            ) {
                Row(
                    modifier = Modifier.padding(12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Default.Warning, contentDescription = null, tint = MaterialTheme.colorScheme.error, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(errorMessage, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onErrorContainer)
                }
            }
        }

        // Message input - let the user write their own message
        if (!showInterestSent) {
            Text(
                text = if (isOffer) "Send a message to the giver" else "Let them know you can help",
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
            )
            Spacer(Modifier.height(8.dp))

            // Quick reply chips
            if (userMessage.isEmpty()) {
                val chips = if (isOffer) listOf(
                    "Hi! Is this still available?",
                    "I\u2019d love this \u2013 when can I collect?",
                    "Could I pick this up today?",
                ) else listOf(
                    "Hi! I have one of these if you\u2019d like it.",
                    "I can help \u2013 shall I drop it off?",
                )
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    chips.forEach { chip ->
                        SuggestionChip(
                            onClick = { userMessage = chip },
                            label = { Text(chip, maxLines = 1, style = MaterialTheme.typography.labelSmall) },
                        )
                    }
                }
                Spacer(Modifier.height(8.dp))
            }

            OutlinedTextField(
                value = userMessage,
                onValueChange = { userMessage = it },
                modifier = Modifier
                    .fillMaxWidth()
                    .height(100.dp),
                placeholder = {
                    Text(
                        if (isOffer) "e.g. Hi! Is this still available? I could collect tomorrow."
                        else "e.g. Hi! I have one of these you\u2019re welcome to have.",
                        color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.4f),
                    )
                },
                shape = RoundedCornerShape(16.dp),
                maxLines = 4,
            )
            Spacer(Modifier.height(12.dp))
            Button(
                onClick = { onSendMessage(userMessage) },
                enabled = !isSending && userMessage.isNotBlank(),
                modifier = Modifier
                    .fillMaxWidth()
                    .height(52.dp),
                shape = RoundedCornerShape(18.dp),
                colors = ButtonDefaults.buttonColors(
                    containerColor = if (isOffer) Color(0xFF008040) else Color(0xFF1565C0),
                ),
            ) {
                if (isSending) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(22.dp),
                        color = Color.White,
                        strokeWidth = 2.dp,
                    )
                    Spacer(Modifier.width(10.dp))
                    Text("Sending\u2026", style = MaterialTheme.typography.titleMedium)
                } else {
                    Icon(
                        Icons.AutoMirrored.Filled.Send,
                        contentDescription = null,
                        modifier = Modifier.size(20.dp),
                    )
                    Spacer(Modifier.width(10.dp))
                    Text(
                        "Send message",
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                    )
                }
            }
        }
    }
}
