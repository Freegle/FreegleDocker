package org.freegle.app.android.ui.screens

import androidx.compose.animation.core.*
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectDragGestures
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import coil3.compose.AsyncImage
import kotlinx.coroutines.launch
import org.freegle.app.android.ui.components.cleanTitle
import org.freegle.app.android.ui.components.formatTimeAgo
import org.freegle.app.api.FreegleApi
import org.freegle.app.model.LocationResult
import org.freegle.app.model.MessageSummary
import org.freegle.app.repository.MessageRepository
import org.koin.compose.koinInject

enum class MessageFilter(val label: String, val types: List<String>?) {
    ALL("All", null),
    OFFERS("Free", listOf("Offer")),
    WANTED("Wanted", listOf("Wanted")),
}

@Composable
fun HomeScreen(
    onMessageClick: (Long) -> Unit,
    onPostWantedClick: () -> Unit = {},
    messageRepository: MessageRepository = koinInject(),
    api: FreegleApi = koinInject(),
) {
    val messages by messageRepository.messages.collectAsState()
    val isLoading by messageRepository.isLoading.collectAsState()
    val scope = rememberCoroutineScope()

    var postcode by remember { mutableStateOf("") }
    var locationName by remember { mutableStateOf("") }
    var lat by remember { mutableStateOf(0.0) }
    var lng by remember { mutableStateOf(0.0) }
    var showPostcodeDialog by remember { mutableStateOf(false) }
    var searchRadius by remember { mutableStateOf(20.0) }
    var currentCardIndex by remember { mutableIntStateOf(0) }
    var selectedFilter by remember { mutableStateOf(MessageFilter.ALL) }

    LaunchedEffect(lat, lng, selectedFilter, searchRadius) {
        if (lat != 0.0 && lng != 0.0) {
            currentCardIndex = 0
            messageRepository.loadLocalMessages(lat, lng, radiusKm = searchRadius, types = selectedFilter.types)
        }
    }

    if (showPostcodeDialog) {
        PostcodeDialog(
            currentPostcode = postcode,
            api = api,
            onDismiss = { showPostcodeDialog = false },
            onPostcodeSet = { pc, name, newLat, newLng ->
                postcode = pc
                locationName = name
                lat = newLat
                lng = newLng
                showPostcodeDialog = false
                currentCardIndex = 0
            },
        )
    }

    Box(modifier = Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        when {
            postcode.isEmpty() -> {
                LocationEmptyState(onSetPostcode = { showPostcodeDialog = true })
            }
            isLoading && messages.isEmpty() -> {
                CardStackLoadingState()
            }
            messages.isEmpty() && !isLoading -> {
                NoItemsState(
                    onPostWanted = onPostWantedClick,
                    onExpandSearch = { searchRadius = 40.0 },
                    onChangePostcode = { showPostcodeDialog = true },
                )
            }
            currentCardIndex >= messages.size -> {
                EndOfStackState(
                    onRestart = {
                        currentCardIndex = 0
                        scope.launch {
                            messageRepository.loadLocalMessages(lat, lng, radiusKm = searchRadius, types = selectedFilter.types)
                        }
                    },
                )
            }
            else -> {
                SwipeCardStack(
                    messages = messages,
                    currentIndex = currentCardIndex,
                    onAdvance = { currentCardIndex++ },
                    onCardClick = onMessageClick,
                )
            }
        }

        // Overlay: location + filter chips at top
        if (postcode.isNotEmpty() && !isLoading) {
            Row(
                modifier = Modifier
                    .align(Alignment.TopCenter)
                    .fillMaxWidth()
                    .padding(horizontal = 12.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                MessageFilter.entries.forEach { filter ->
                    FilterChip(
                        selected = selectedFilter == filter,
                        onClick = {
                            selectedFilter = filter
                            currentCardIndex = 0
                        },
                        label = { Text(filter.label, style = MaterialTheme.typography.labelSmall) },
                        colors = FilterChipDefaults.filterChipColors(
                            selectedContainerColor = MaterialTheme.colorScheme.primaryContainer,
                        ),
                    )
                }
                Spacer(Modifier.weight(1f))
                FilledTonalIconButton(
                    onClick = { showPostcodeDialog = true },
                    modifier = Modifier.size(36.dp),
                ) {
                    Icon(
                        Icons.Default.LocationOn,
                        contentDescription = if (locationName.isNotEmpty()) locationName else "Location",
                        modifier = Modifier.size(18.dp),
                    )
                }
            }
        }
    }
}

@Composable
private fun SwipeCardStack(
    messages: List<MessageSummary>,
    currentIndex: Int,
    onAdvance: () -> Unit,
    onCardClick: (Long) -> Unit,
) {
    val scope = rememberCoroutineScope()
    val offsetX = remember { Animatable(0f) }
    val offsetY = remember { Animatable(0f) }

    LaunchedEffect(currentIndex) {
        offsetX.snapTo(0f)
        offsetY.snapTo(0f)
    }

    val swipeThreshold = 130f
    val rightAlpha = (offsetX.value / swipeThreshold).coerceIn(0f, 1f)
    val leftAlpha = (-offsetX.value / swipeThreshold).coerceIn(0f, 1f)
    val rotationDeg = offsetX.value * 0.035f

    Box(modifier = Modifier.fillMaxSize()) {
        // Background cards (depth effect: 2nd and 3rd cards behind)
        val backCount = minOf(2, messages.size - currentIndex - 1)
        for (i in backCount downTo 1) {
            val bgMessage = messages[currentIndex + i]
            val scale = 1f - i * 0.05f
            val yTranslate = (-i * 18).toFloat()
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .fillMaxHeight(0.78f)
                    .align(Alignment.Center)
                    .padding(horizontal = 20.dp)
                    .graphicsLayer {
                        scaleX = scale
                        scaleY = scale
                        translationY = yTranslate
                    }
                    .clip(RoundedCornerShape(28.dp)),
            ) {
                BackgroundCard(message = bgMessage)
            }
        }

        // Top swipeable card
        if (currentIndex < messages.size) {
            val message = messages[currentIndex]
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .fillMaxHeight(0.78f)
                    .align(Alignment.Center)
                    .padding(horizontal = 16.dp)
                    .graphicsLayer {
                        translationX = offsetX.value
                        translationY = offsetY.value
                        rotationZ = rotationDeg
                    }
                    .clip(RoundedCornerShape(28.dp))
                    .pointerInput(currentIndex) {
                        detectDragGestures(
                            onDragEnd = {
                                scope.launch {
                                    when {
                                        offsetX.value > swipeThreshold -> {
                                            offsetX.animateTo(2200f, tween(280, easing = FastOutLinearInEasing))
                                            onAdvance()
                                        }
                                        offsetX.value < -swipeThreshold -> {
                                            offsetX.animateTo(-2200f, tween(280, easing = FastOutLinearInEasing))
                                            onAdvance()
                                        }
                                        else -> {
                                            launch { offsetX.animateTo(0f, spring(dampingRatio = 0.6f, stiffness = 700f)) }
                                            launch { offsetY.animateTo(0f, spring(dampingRatio = 0.6f, stiffness = 700f)) }
                                        }
                                    }
                                }
                            },
                            onDrag = { change, dragAmount ->
                                change.consume()
                                scope.launch {
                                    offsetX.snapTo(offsetX.value + dragAmount.x)
                                    offsetY.snapTo(offsetY.value + dragAmount.y)
                                }
                            },
                        )
                    },
            ) {
                SwipeCard(message = message, onClick = { onCardClick(message.id) })

                // "YES!" green overlay when swiping right
                if (rightAlpha > 0.05f) {
                    Box(
                        modifier = Modifier
                            .fillMaxSize()
                            .background(Color(0xFF00B050).copy(alpha = (rightAlpha * 0.45f).coerceAtMost(0.45f))),
                        contentAlignment = Alignment.Center,
                    ) {
                        if (rightAlpha > 0.5f) {
                            Text(
                                "YES!",
                                style = MaterialTheme.typography.displaySmall,
                                fontWeight = FontWeight.Black,
                                color = Color.White,
                            )
                        }
                    }
                }

                // "SKIP" gray overlay when swiping left
                if (leftAlpha > 0.05f) {
                    Box(
                        modifier = Modifier
                            .fillMaxSize()
                            .background(Color.Black.copy(alpha = (leftAlpha * 0.4f).coerceAtMost(0.4f))),
                        contentAlignment = Alignment.Center,
                    ) {
                        if (leftAlpha > 0.5f) {
                            Text(
                                "SKIP",
                                style = MaterialTheme.typography.displaySmall,
                                fontWeight = FontWeight.Black,
                                color = Color.White,
                            )
                        }
                    }
                }
            }

            // Action buttons and counter beneath the card
            Column(
                modifier = Modifier
                    .align(Alignment.BottomCenter)
                    .padding(bottom = 20.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Text(
                    "${currentIndex + 1} / ${messages.size}",
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Spacer(Modifier.height(10.dp))
                Row(
                    horizontalArrangement = Arrangement.spacedBy(28.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    // Skip
                    FloatingActionButton(
                        onClick = {
                            scope.launch {
                                offsetX.animateTo(-2200f, tween(280, easing = FastOutLinearInEasing))
                                onAdvance()
                            }
                        },
                        shape = CircleShape,
                        containerColor = MaterialTheme.colorScheme.surfaceVariant,
                        contentColor = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.size(54.dp),
                        elevation = FloatingActionButtonDefaults.elevation(2.dp),
                    ) {
                        Icon(Icons.Default.Close, contentDescription = "Skip")
                    }
                    // Interested
                    FloatingActionButton(
                        onClick = {
                            scope.launch {
                                offsetX.animateTo(2200f, tween(280, easing = FastOutLinearInEasing))
                                onAdvance()
                            }
                        },
                        shape = CircleShape,
                        containerColor = MaterialTheme.colorScheme.primary,
                        contentColor = MaterialTheme.colorScheme.onPrimary,
                        modifier = Modifier.size(68.dp),
                        elevation = FloatingActionButtonDefaults.elevation(6.dp),
                    ) {
                        Icon(Icons.Default.Favorite, contentDescription = "Interested!", modifier = Modifier.size(30.dp))
                    }
                    // Save / Bookmark
                    FloatingActionButton(
                        onClick = {
                            scope.launch {
                                offsetY.animateTo(-2200f, tween(280, easing = FastOutLinearInEasing))
                                onAdvance()
                            }
                        },
                        shape = CircleShape,
                        containerColor = MaterialTheme.colorScheme.secondaryContainer,
                        contentColor = MaterialTheme.colorScheme.onSecondaryContainer,
                        modifier = Modifier.size(54.dp),
                        elevation = FloatingActionButtonDefaults.elevation(2.dp),
                    ) {
                        Icon(Icons.Default.Bookmark, contentDescription = "Save for later")
                    }
                }
            }
        }
    }
}

@Composable
private fun SwipeCard(
    message: MessageSummary,
    onClick: () -> Unit,
) {
    val imageUrl = message.messageAttachments?.firstOrNull()?.path
        ?: message.messageAttachments?.firstOrNull()?.paththumb
    val title = cleanTitle(message.subject ?: "Item")
    val isOffer = message.type == "Offer"
    val location = message.location?.areaname
        ?: message.messageGroups?.firstOrNull()?.namedisplay
    val timeStr = message.arrival ?: message.date

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Color(0xFF1C1B1A)),
    ) {
        if (imageUrl != null) {
            AsyncImage(
                model = imageUrl,
                contentDescription = title,
                modifier = Modifier.fillMaxSize(),
                contentScale = ContentScale.Crop,
            )
        } else {
            // Gradient placeholder using initials
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
                    color = Color.White.copy(alpha = 0.25f),
                    fontWeight = FontWeight.Black,
                )
            }
        }

        // Tap to view detail - subtle hint top-right
        Surface(
            onClick = onClick,
            modifier = Modifier
                .align(Alignment.TopEnd)
                .padding(14.dp),
            shape = CircleShape,
            color = Color.Black.copy(alpha = 0.28f),
        ) {
            Icon(
                Icons.Default.OpenInFull,
                contentDescription = "View details",
                modifier = Modifier
                    .padding(8.dp)
                    .size(16.dp),
                tint = Color.White,
            )
        }

        // Bottom gradient + info
        Box(
            modifier = Modifier
                .align(Alignment.BottomCenter)
                .fillMaxWidth()
                .background(
                    Brush.verticalGradient(
                        colors = listOf(Color.Transparent, Color.Black.copy(alpha = 0.8f)),
                        startY = 0f,
                        endY = Float.POSITIVE_INFINITY,
                    ),
                )
                .padding(horizontal = 20.dp, vertical = 20.dp),
        ) {
            Column {
                // Type badge
                Surface(
                    shape = RoundedCornerShape(6.dp),
                    color = if (isOffer) Color(0xFF00B050) else Color(0xFFFF6B35),
                    modifier = Modifier.padding(bottom = 10.dp),
                ) {
                    Text(
                        text = if (isOffer) "FREE" else "WANTED",
                        modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp),
                        style = MaterialTheme.typography.labelSmall,
                        color = Color.White,
                        fontWeight = FontWeight.Bold,
                    )
                }

                Text(
                    text = title,
                    style = MaterialTheme.typography.headlineSmall,
                    color = Color.White,
                    fontWeight = FontWeight.Bold,
                    maxLines = 2,
                )

                Spacer(Modifier.height(6.dp))

                Row(
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    if (location != null) {
                        Icon(
                            Icons.Default.LocationOn,
                            contentDescription = null,
                            modifier = Modifier.size(13.dp),
                            tint = Color.White.copy(alpha = 0.75f),
                        )
                        Text(
                            text = location,
                            style = MaterialTheme.typography.bodySmall,
                            color = Color.White.copy(alpha = 0.75f),
                        )
                    }
                    if (timeStr != null) {
                        Text(
                            text = "· ${formatTimeAgo(timeStr)}",
                            style = MaterialTheme.typography.bodySmall,
                            color = Color.White.copy(alpha = 0.55f),
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun BackgroundCard(message: MessageSummary) {
    val imageUrl = message.messageAttachments?.firstOrNull()?.paththumb
        ?: message.messageAttachments?.firstOrNull()?.path
    val isOffer = message.type == "Offer"

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Color(0xFF1C1B1A)),
    ) {
        if (imageUrl != null) {
            AsyncImage(
                model = imageUrl,
                contentDescription = null,
                modifier = Modifier.fillMaxSize(),
                contentScale = ContentScale.Crop,
            )
        } else {
            Box(
                modifier = Modifier
                    .fillMaxSize()
                    .background(
                        if (isOffer) Color(0xFF00B050).copy(alpha = 0.4f)
                        else Color(0xFFFF6B35).copy(alpha = 0.4f),
                    ),
            )
        }
    }
}

@Composable
private fun LocationEmptyState(onSetPostcode: () -> Unit) {
    Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            modifier = Modifier.padding(40.dp),
        ) {
            Box(
                modifier = Modifier
                    .size(120.dp)
                    .background(MaterialTheme.colorScheme.primaryContainer, CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    Icons.Default.LocationOn,
                    contentDescription = null,
                    modifier = Modifier.size(60.dp),
                    tint = MaterialTheme.colorScheme.primary,
                )
            }
            Spacer(Modifier.height(32.dp))
            Text(
                "What's free nearby?",
                style = MaterialTheme.typography.headlineMedium,
                fontWeight = FontWeight.Bold,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(12.dp))
            Text(
                "Thousands of items are being given away near you right now. Swipe through them like you would photos.",
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(32.dp))
            Button(
                onClick = onSetPostcode,
                modifier = Modifier
                    .fillMaxWidth(0.75f)
                    .height(52.dp),
            ) {
                Icon(Icons.Default.LocationOn, contentDescription = null)
                Spacer(Modifier.width(8.dp))
                Text("Find items near me", style = MaterialTheme.typography.titleSmall)
            }
        }
    }
}

@Composable
private fun CardStackLoadingState() {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .fillMaxHeight(0.78f)
            .padding(horizontal = 16.dp)
            .clip(RoundedCornerShape(28.dp))
            .background(MaterialTheme.colorScheme.surfaceVariant),
        contentAlignment = Alignment.Center,
    ) {
        CircularProgressIndicator(color = MaterialTheme.colorScheme.primary)
    }
}

@Composable
private fun NoItemsState(
    onPostWanted: () -> Unit,
    onExpandSearch: () -> Unit,
    onChangePostcode: () -> Unit,
) {
    Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            modifier = Modifier.padding(40.dp),
        ) {
            Text(
                "Nothing here yet",
                style = MaterialTheme.typography.headlineSmall,
                fontWeight = FontWeight.Bold,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(8.dp))
            Text(
                "New items pop up every day. Or be the first to give something in your area!",
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(24.dp))
            Button(onClick = onPostWanted) { Text("Post a Wanted ad") }
            Spacer(Modifier.height(8.dp))
            OutlinedButton(onClick = onExpandSearch) { Text("Search wider area") }
            Spacer(Modifier.height(8.dp))
            TextButton(onClick = onChangePostcode) { Text("Change postcode") }
        }
    }
}

@Composable
private fun EndOfStackState(onRestart: () -> Unit) {
    Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            modifier = Modifier.padding(40.dp),
        ) {
            Icon(
                Icons.Default.DoneAll,
                contentDescription = null,
                modifier = Modifier.size(72.dp),
                tint = MaterialTheme.colorScheme.primary,
            )
            Spacer(Modifier.height(16.dp))
            Text(
                "All caught up!",
                style = MaterialTheme.typography.headlineSmall,
                fontWeight = FontWeight.Bold,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(8.dp))
            Text(
                "You've seen all nearby items. Check back soon - new things appear every day.",
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(24.dp))
            Button(onClick = onRestart) { Text("Start over") }
        }
    }
}

@Composable
fun PostcodeDialog(
    currentPostcode: String,
    api: FreegleApi,
    onDismiss: () -> Unit,
    onPostcodeSet: (postcode: String, locationName: String, lat: Double, lng: Double) -> Unit,
) {
    var postcodeInput by remember { mutableStateOf(currentPostcode) }
    var isLooking by remember { mutableStateOf(false) }
    var lookupError by remember { mutableStateOf<String?>(null) }
    var suggestions by remember { mutableStateOf<List<LocationResult>>(emptyList()) }
    val scope = rememberCoroutineScope()

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Your postcode") },
        text = {
            Column {
                Text(
                    "Enter your postcode to find free items nearby.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Spacer(Modifier.height(16.dp))
                OutlinedTextField(
                    value = postcodeInput,
                    onValueChange = { input ->
                        postcodeInput = input.uppercase()
                        lookupError = null
                        if (input.length >= 2) {
                            scope.launch { suggestions = api.searchLocations(input.trim()) }
                        } else {
                            suggestions = emptyList()
                        }
                    },
                    label = { Text("Postcode") },
                    placeholder = { Text("e.g. EH1 1AA") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                    isError = lookupError != null,
                    supportingText = lookupError?.let { { Text(it) } },
                )
                if (suggestions.isNotEmpty()) {
                    Spacer(Modifier.height(8.dp))
                    LazyColumn(modifier = Modifier.heightIn(max = 200.dp)) {
                        items(suggestions) { location ->
                            ListItem(
                                headlineContent = {
                                    Text(location.name ?: "", style = MaterialTheme.typography.bodyMedium)
                                },
                                supportingContent = {
                                    val area = location.areaname ?: location.area?.name
                                    if (area != null) {
                                        Text(
                                            area,
                                            style = MaterialTheme.typography.bodySmall,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                                        )
                                    }
                                },
                                leadingContent = {
                                    Icon(
                                        Icons.Default.LocationOn,
                                        contentDescription = null,
                                        tint = MaterialTheme.colorScheme.primary,
                                        modifier = Modifier.size(20.dp),
                                    )
                                },
                                modifier = Modifier.clickable {
                                    val name = location.areaname ?: location.area?.name ?: ""
                                    onPostcodeSet(location.name ?: "", name, location.lat, location.lng)
                                },
                            )
                            HorizontalDivider()
                        }
                    }
                }
            }
        },
        confirmButton = {
            Button(
                onClick = {
                    if (postcodeInput.isBlank()) { lookupError = "Please enter a postcode"; return@Button }
                    val first = suggestions.firstOrNull()
                    if (first != null) {
                        val name = first.areaname ?: first.area?.name ?: ""
                        onPostcodeSet(first.name ?: postcodeInput, name, first.lat, first.lng)
                    } else {
                        isLooking = true; lookupError = null
                        scope.launch {
                            val results = api.searchLocations(postcodeInput.trim())
                            isLooking = false
                            val match = results.firstOrNull()
                            if (match != null) {
                                val name = match.areaname ?: match.area?.name ?: ""
                                onPostcodeSet(match.name ?: postcodeInput, name, match.lat, match.lng)
                            } else {
                                lookupError = "Postcode not found. Check and try again."
                            }
                        }
                    }
                },
                enabled = !isLooking && postcodeInput.isNotBlank(),
            ) {
                if (isLooking) CircularProgressIndicator(modifier = Modifier.size(16.dp), strokeWidth = 2.dp)
                else Text("Find items")
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}
