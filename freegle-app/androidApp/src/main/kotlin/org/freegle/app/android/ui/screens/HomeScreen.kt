package org.freegle.app.android.ui.screens

import androidx.compose.animation.core.*
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import android.Manifest
import android.content.pm.PackageManager
import android.location.LocationManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.ui.platform.LocalContext
import androidx.core.content.ContextCompat
import coil3.compose.AsyncImage
import kotlinx.coroutines.launch
import org.freegle.app.android.data.FreeglePreferences
import org.freegle.app.android.ui.components.cleanTitle
import org.freegle.app.android.ui.components.formatTimeAgo
import org.freegle.app.api.FreegleApi
import org.freegle.app.model.LocationResult
import org.freegle.app.model.MessageSummary
import org.freegle.app.repository.MessageRepository
import org.koin.compose.koinInject

enum class MessageFilter(val label: String, val types: List<String>?) {
    ALL("All", null),
    OFFERS("Offer", listOf("Offer")),
    WANTED("Wanted", listOf("Wanted")),
}

@Composable
fun HomeScreen(
    onMessageClick: (Long) -> Unit,
    onPostWantedClick: () -> Unit = {},
    messageRepository: MessageRepository = koinInject(),
    api: FreegleApi = koinInject(),
    prefs: FreeglePreferences = koinInject(),
) {
    val messages by messageRepository.messages.collectAsState()
    val isLoading by messageRepository.isLoading.collectAsState()
    val error by messageRepository.error.collectAsState()
    val scope = rememberCoroutineScope()
    val context = LocalContext.current

    var postcode by remember { mutableStateOf("") }
    var locationName by remember { mutableStateOf("") }
    var lat by remember { mutableStateOf(0.0) }
    var lng by remember { mutableStateOf(0.0) }
    var showPostcodeDialog by remember { mutableStateOf(false) }
    var searchRadius by remember { mutableStateOf(20.0) }
    var selectedFilter by remember { mutableStateOf(MessageFilter.ALL) }
    var showSearch by remember { mutableStateOf(false) }
    var searchQuery by remember { mutableStateOf("") }
    var searchResults by remember { mutableStateOf<List<MessageSummary>?>(null) }
    var isSearching by remember { mutableStateOf(false) }
    var isDetectingLocation by remember { mutableStateOf(false) }

    // Restore saved location on first launch, or auto-detect GPS
    LaunchedEffect(Unit) {
        val savedPostcode = prefs.getPostcode()
        if (savedPostcode.isNotEmpty()) {
            postcode = savedPostcode
            locationName = prefs.getLocationName()
            lat = prefs.getLat()
            lng = prefs.getLng()
        } else {
            // Auto-detect: check if we already have location permission
            val hasPermission = ContextCompat.checkSelfPermission(
                context, Manifest.permission.ACCESS_COARSE_LOCATION
            ) == PackageManager.PERMISSION_GRANTED
            if (hasPermission) {
                isDetectingLocation = true
                detectAndSetLocation(context, api, prefs) { pc, name, newLat, newLng ->
                    if (pc.isNotEmpty()) {
                        postcode = pc
                        locationName = name
                        lat = newLat
                        lng = newLng
                    }
                    isDetectingLocation = false
                }
            }
        }
    }

    // GPS location permission launcher
    val locationPermissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        if (granted) {
            isDetectingLocation = true
            scope.launch {
                detectAndSetLocation(context, api, prefs) { pc, name, newLat, newLng ->
                    if (pc.isNotEmpty()) {
                        postcode = pc
                        locationName = name
                        lat = newLat
                        lng = newLng
                    } else {
                        showPostcodeDialog = true
                    }
                    isDetectingLocation = false
                }
            }
        } else {
            // Permission denied - show manual postcode dialog
            showPostcodeDialog = true
        }
    }

    // Cache user info (userId -> displayname, profileUrl)
    val userCache = remember { mutableStateMapOf<Long, Pair<String?, String?>>() }

    LaunchedEffect(lat, lng, selectedFilter, searchRadius) {
        if (lat != 0.0 && lng != 0.0) {
            messageRepository.loadLocalMessages(lat, lng, radiusKm = searchRadius, types = selectedFilter.types)
        }
    }

    // Pre-fetch user info for visible messages
    LaunchedEffect(messages) {
        val userIds = messages.mapNotNull { it.fromuser }.distinct()
        for (userId in userIds) {
            if (userId !in userCache) {
                val user = api.getUser(userId)
                userCache[userId] = Pair(
                    user?.displayname ?: user?.firstname,
                    user?.profile?.url ?: user?.profile?.paththumb,
                )
            }
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
                scope.launch { prefs.saveLocation(pc, name, newLat, newLng) }
            },
        )
    }

    Column(modifier = Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        when {
            postcode.isEmpty() -> {
                LocationEmptyState(
                    isDetecting = isDetectingLocation,
                    onDetectLocation = {
                        val hasPermission = ContextCompat.checkSelfPermission(
                            context, Manifest.permission.ACCESS_COARSE_LOCATION
                        ) == PackageManager.PERMISSION_GRANTED
                        if (hasPermission) {
                            isDetectingLocation = true
                            scope.launch {
                                detectAndSetLocation(context, api, prefs) { pc, name, newLat, newLng ->
                                    if (pc.isNotEmpty()) {
                                        postcode = pc
                                        locationName = name
                                        lat = newLat
                                        lng = newLng
                                    } else {
                                        showPostcodeDialog = true
                                    }
                                    isDetectingLocation = false
                                }
                            }
                        } else {
                            locationPermissionLauncher.launch(Manifest.permission.ACCESS_COARSE_LOCATION)
                        }
                    },
                    onSetPostcode = { showPostcodeDialog = true },
                )
            }
            isLoading && messages.isEmpty() -> {
                FeedSkeletonLoading()
            }
            messages.isEmpty() && !isLoading -> {
                NoItemsState(
                    onPostWanted = onPostWantedClick,
                    onExpandSearch = { searchRadius = 40.0 },
                    onChangePostcode = { showPostcodeDialog = true },
                )
            }
            else -> {
                // Header: location + filter chips + search
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 16.dp, vertical = 8.dp),
                ) {
                    // Location header
                    Row(
                        verticalAlignment = Alignment.CenterVertically,
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Column(modifier = Modifier.weight(1f)) {
                            Text(
                                "Your community",
                                style = MaterialTheme.typography.headlineMedium,
                                fontWeight = FontWeight.Bold,
                            )
                            Row(
                                verticalAlignment = Alignment.CenterVertically,
                                horizontalArrangement = Arrangement.spacedBy(4.dp),
                            ) {
                                Icon(
                                    Icons.Default.LocationOn,
                                    contentDescription = null,
                                    modifier = Modifier.size(14.dp),
                                    tint = MaterialTheme.colorScheme.primary,
                                )
                                Text(
                                    locationName.ifEmpty { postcode },
                                    style = MaterialTheme.typography.bodyMedium,
                                    color = MaterialTheme.colorScheme.primary,
                                    fontWeight = FontWeight.Medium,
                                )
                            }
                        }
                        FilledTonalIconButton(
                            onClick = { showSearch = !showSearch },
                            modifier = Modifier.size(38.dp),
                        ) {
                            Icon(Icons.Default.Search, contentDescription = "Search", modifier = Modifier.size(20.dp))
                        }
                        Spacer(Modifier.width(6.dp))
                        FilledTonalIconButton(
                            onClick = { showPostcodeDialog = true },
                            modifier = Modifier.size(38.dp),
                        ) {
                            Icon(Icons.Default.EditLocationAlt, contentDescription = "Change location", modifier = Modifier.size(20.dp))
                        }
                    }

                    Spacer(Modifier.height(10.dp))

                    // Filter chips
                    Row(
                        horizontalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        MessageFilter.entries.forEach { filter ->
                            FilterChip(
                                selected = selectedFilter == filter,
                                onClick = {
                                    selectedFilter = filter
                                    searchResults = null
                                    searchQuery = ""
                                    showSearch = false
                                },
                                label = { Text(filter.label) },
                                colors = FilterChipDefaults.filterChipColors(
                                    selectedContainerColor = MaterialTheme.colorScheme.primaryContainer,
                                ),
                            )
                        }
                    }

                    // Search bar
                    if (showSearch) {
                        Spacer(Modifier.height(8.dp))
                        // Debounced search
                        LaunchedEffect(searchQuery) {
                            if (searchQuery.length >= 2) {
                                isSearching = true
                                kotlinx.coroutines.delay(300) // Debounce 300ms
                                searchResults = messageRepository.searchMessages(searchQuery)
                                isSearching = false
                            } else {
                                searchResults = null
                                isSearching = false
                            }
                        }
                        OutlinedTextField(
                            value = searchQuery,
                            onValueChange = { searchQuery = it },
                            modifier = Modifier.fillMaxWidth(),
                            placeholder = { Text("Search for items\u2026") },
                            leadingIcon = { Icon(Icons.Default.Search, contentDescription = null, modifier = Modifier.size(20.dp)) },
                            trailingIcon = {
                                if (searchQuery.isNotEmpty()) {
                                    IconButton(onClick = { searchQuery = ""; searchResults = null }) {
                                        Icon(Icons.Default.Close, contentDescription = "Clear", modifier = Modifier.size(18.dp))
                                    }
                                }
                            },
                            singleLine = true,
                            shape = RoundedCornerShape(16.dp),
                        )
                    }
                }

                // Person-centred feed
                val giverGroups = remember(messages, userCache.size) {
                    val (withUser, noUser) = messages.partition { it.fromuser != null }
                    val groups = withUser
                        .groupBy { it.fromuser!! }
                        .map { (userId, msgs) ->
                            GiverGroup(
                                userId = userId,
                                userName = userCache[userId]?.first,
                                userProfileUrl = userCache[userId]?.second,
                                messages = msgs.sortedByDescending { it.arrival ?: it.date },
                                location = msgs.firstOrNull()?.location?.areaname
                                    ?: msgs.firstOrNull()?.messageGroups?.firstOrNull()?.namedisplay,
                            )
                        }
                        .sortedByDescending { it.messages.size }
                        .toMutableList()

                    // Include messages with no user info in a generic group
                    if (noUser.isNotEmpty()) {
                        groups.add(GiverGroup(
                            userId = 0L,
                            userName = null,
                            userProfileUrl = null,
                            messages = noUser.sortedByDescending { it.arrival ?: it.date },
                            location = noUser.firstOrNull()?.location?.areaname,
                        ))
                    }
                    groups.toList()
                }

                // Search results overlay
                if (isSearching) {
                    Box(
                        modifier = Modifier.fillMaxWidth().padding(32.dp),
                        contentAlignment = Alignment.Center,
                    ) {
                        CircularProgressIndicator(modifier = Modifier.size(32.dp))
                    }
                } else if (searchResults != null) {
                    SearchResultsList(
                        results = searchResults!!,
                        query = searchQuery,
                        onMessageClick = { id ->
                            onMessageClick(id)
                            showSearch = false
                            searchQuery = ""
                            searchResults = null
                        },
                    )
                } else {
                    @OptIn(ExperimentalMaterial3Api::class)
                    PullToRefreshBox(
                        isRefreshing = isLoading,
                        onRefresh = {
                            scope.launch {
                                messageRepository.loadLocalMessages(lat, lng, radiusKm = searchRadius, types = selectedFilter.types)
                            }
                        },
                        modifier = Modifier.fillMaxSize(),
                    ) {
                        LazyColumn(
                            contentPadding = PaddingValues(bottom = 16.dp),
                            verticalArrangement = Arrangement.spacedBy(4.dp),
                        ) {
                            items(giverGroups, key = { it.userId }) { group ->
                                GiverSection(
                                    group = group,
                                    onMessageClick = onMessageClick,
                                )
                            }

                            // End of feed
                            item {
                                Column(
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .padding(32.dp),
                                    horizontalAlignment = Alignment.CenterHorizontally,
                                ) {
                                    Icon(
                                        Icons.Default.DoneAll,
                                        contentDescription = null,
                                        modifier = Modifier.size(40.dp),
                                        tint = MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.5f),
                                    )
                                    Spacer(Modifier.height(8.dp))
                                    Text(
                                        "You\u2019ve seen all nearby items",
                                        style = MaterialTheme.typography.bodyMedium,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                    Spacer(Modifier.height(12.dp))
                                    OutlinedButton(onClick = onPostWantedClick) {
                                        Icon(Icons.Default.PostAdd, contentDescription = null, modifier = Modifier.size(18.dp))
                                        Spacer(Modifier.width(6.dp))
                                        Text("Post a Wanted")
                                    }
                                }
                            }
                        }
                    }
                }

                // Error snackbar
                if (error != null) {
                    Spacer(Modifier.height(4.dp))
                    Surface(
                        modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp),
                        shape = RoundedCornerShape(12.dp),
                        color = MaterialTheme.colorScheme.errorContainer,
                    ) {
                        Row(
                            modifier = Modifier.padding(12.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(Icons.Default.Warning, contentDescription = null, tint = MaterialTheme.colorScheme.error, modifier = Modifier.size(20.dp))
                            Spacer(Modifier.width(8.dp))
                            Text(error ?: "", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onErrorContainer, modifier = Modifier.weight(1f))
                            TextButton(onClick = {
                                scope.launch { messageRepository.loadLocalMessages(lat, lng, radiusKm = searchRadius, types = selectedFilter.types) }
                            }) { Text("Retry", style = MaterialTheme.typography.labelSmall) }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun FeedSkeletonLoading() {
    val shimmerColors = listOf(
        MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.6f),
        MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.2f),
        MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.6f),
    )
    val transition = rememberInfiniteTransition(label = "shimmer")
    val translateAnim by transition.animateFloat(
        initialValue = 0f,
        targetValue = 1000f,
        animationSpec = infiniteRepeatable(
            animation = tween(1200, easing = LinearEasing),
            repeatMode = RepeatMode.Restart,
        ),
        label = "shimmer_translate",
    )
    val brush = Brush.linearGradient(
        colors = shimmerColors,
        start = androidx.compose.ui.geometry.Offset(translateAnim - 200f, 0f),
        end = androidx.compose.ui.geometry.Offset(translateAnim, 0f),
    )

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(16.dp),
    ) {
        // Header skeleton
        Box(
            modifier = Modifier
                .width(180.dp)
                .height(28.dp)
                .clip(RoundedCornerShape(8.dp))
                .background(brush),
        )
        Spacer(Modifier.height(6.dp))
        Box(
            modifier = Modifier
                .width(120.dp)
                .height(16.dp)
                .clip(RoundedCornerShape(6.dp))
                .background(brush),
        )
        Spacer(Modifier.height(16.dp))

        // Skeleton cards
        repeat(4) {
            Card(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(vertical = 4.dp),
                shape = RoundedCornerShape(20.dp),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceContainerLowest),
            ) {
                Column(modifier = Modifier.padding(16.dp)) {
                    Row(
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(12.dp),
                    ) {
                        Box(
                            modifier = Modifier
                                .size(44.dp)
                                .clip(CircleShape)
                                .background(brush),
                        )
                        Column {
                            Box(
                                modifier = Modifier
                                    .width(140.dp)
                                    .height(14.dp)
                                    .clip(RoundedCornerShape(4.dp))
                                    .background(brush),
                            )
                            Spacer(Modifier.height(6.dp))
                            Box(
                                modifier = Modifier
                                    .width(100.dp)
                                    .height(10.dp)
                                    .clip(RoundedCornerShape(4.dp))
                                    .background(brush),
                            )
                        }
                    }
                    Spacer(Modifier.height(12.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        repeat(3) {
                            Box(
                                modifier = Modifier
                                    .width(130.dp)
                                    .height(100.dp)
                                    .clip(RoundedCornerShape(12.dp))
                                    .background(brush),
                            )
                        }
                    }
                }
            }
        }
    }
}

private data class GiverGroup(
    val userId: Long,
    val userName: String?,
    val userProfileUrl: String?,
    val messages: List<MessageSummary>,
    val location: String?,
)

@Composable
private fun GiverSection(
    group: GiverGroup,
    onMessageClick: (Long) -> Unit,
) {
    val name = group.userName ?: "A Freegler"
    val offerCount = group.messages.count { it.type == "Offer" }
    val wantedCount = group.messages.count { it.type == "Wanted" }
    val subtitle = buildString {
        if (offerCount > 0) append("$offerCount ${if (offerCount == 1) "item" else "items"} to give away")
        if (offerCount > 0 && wantedCount > 0) append(" \u00b7 ")
        if (wantedCount > 0) append("$wantedCount wanted")
    }

    Card(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 4.dp),
        shape = RoundedCornerShape(20.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceContainerLowest),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
    ) {
        Column(modifier = Modifier.padding(16.dp)) {
            // Person header
            Row(
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                // Avatar
                if (group.userProfileUrl != null) {
                    AsyncImage(
                        model = group.userProfileUrl,
                        contentDescription = name,
                        modifier = Modifier
                            .size(44.dp)
                            .clip(CircleShape),
                        contentScale = ContentScale.Crop,
                    )
                } else {
                    Box(
                        modifier = Modifier
                            .size(44.dp)
                            .background(MaterialTheme.colorScheme.primaryContainer, CircleShape),
                        contentAlignment = Alignment.Center,
                    ) {
                        Text(
                            name.first().uppercase(),
                            style = MaterialTheme.typography.titleMedium,
                            fontWeight = FontWeight.Bold,
                            color = MaterialTheme.colorScheme.onPrimaryContainer,
                        )
                    }
                }

                Column(modifier = Modifier.weight(1f)) {
                    Text(
                        name,
                        style = MaterialTheme.typography.titleSmall,
                        fontWeight = FontWeight.Bold,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                    Text(
                        subtitle,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }

                // Location
                if (group.location != null) {
                    Row(
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(2.dp),
                    ) {
                        Icon(
                            Icons.Default.LocationOn,
                            contentDescription = null,
                            modifier = Modifier.size(12.dp),
                            tint = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        Text(
                            group.location,
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }

            Spacer(Modifier.height(12.dp))

            // Item thumbnails - horizontal scroll
            if (group.messages.size == 1) {
                // Single item — show as a full-width card
                val msg = group.messages.first()
                SingleItemCard(message = msg, onClick = { onMessageClick(msg.id) })
            } else {
                // Multiple items — horizontal row
                LazyRow(
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    items(group.messages, key = { it.id }) { msg ->
                        ItemThumbnail(message = msg, onClick = { onMessageClick(msg.id) })
                    }
                }
            }
        }
    }
}

@Composable
private fun SingleItemCard(
    message: MessageSummary,
    onClick: () -> Unit,
) {
    val imageUrl = message.messageAttachments?.firstOrNull()?.path
        ?: message.messageAttachments?.firstOrNull()?.paththumb
    val title = cleanTitle(message.subject ?: "Item")
    val isOffer = message.type == "Offer"
    val timeStr = message.arrival ?: message.date

    Card(
        onClick = onClick,
        shape = RoundedCornerShape(14.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceContainerLow),
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .height(IntrinsicSize.Min),
        ) {
            // Thumbnail
            if (imageUrl != null) {
                AsyncImage(
                    model = imageUrl,
                    contentDescription = title,
                    modifier = Modifier
                        .width(100.dp)
                        .fillMaxHeight()
                        .clip(RoundedCornerShape(topStart = 14.dp, bottomStart = 14.dp)),
                    contentScale = ContentScale.Crop,
                )
            } else {
                Box(
                    modifier = Modifier
                        .width(100.dp)
                        .fillMaxHeight()
                        .background(
                            if (isOffer) Color(0xFF008040).copy(alpha = 0.15f)
                            else Color(0xFF1565C0).copy(alpha = 0.15f),
                        ),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(
                        title.take(2).uppercase(),
                        style = MaterialTheme.typography.titleLarge,
                        color = if (isOffer) Color(0xFF008040) else Color(0xFF1565C0),
                        fontWeight = FontWeight.Bold,
                    )
                }
            }

            // Details
            Column(
                modifier = Modifier
                    .weight(1f)
                    .padding(12.dp),
            ) {
                // Type badge
                Surface(
                    shape = RoundedCornerShape(4.dp),
                    color = if (isOffer) Color(0xFF008040) else Color(0xFF1565C0),
                ) {
                    Text(
                        if (isOffer) "OFFER" else "WANTED",
                        modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp),
                        style = MaterialTheme.typography.labelSmall,
                        color = Color.White,
                        fontWeight = FontWeight.Bold,
                    )
                }
                Spacer(Modifier.height(6.dp))
                Text(
                    title,
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
                if (timeStr != null) {
                    Spacer(Modifier.height(4.dp))
                    Text(
                        formatTimeAgo(timeStr),
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                if (message.replycount > 0) {
                    Spacer(Modifier.height(2.dp))
                    Text(
                        "${message.replycount} ${if (message.replycount == 1) "reply" else "replies"}",
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}

@Composable
private fun ItemThumbnail(
    message: MessageSummary,
    onClick: () -> Unit,
) {
    val imageUrl = message.messageAttachments?.firstOrNull()?.paththumb
        ?: message.messageAttachments?.firstOrNull()?.path
    val title = cleanTitle(message.subject ?: "Item")
    val isOffer = message.type == "Offer"

    Card(
        onClick = onClick,
        modifier = Modifier.width(130.dp),
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceContainerLow),
    ) {
        Column {
            // Image
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .height(100.dp),
            ) {
                if (imageUrl != null) {
                    AsyncImage(
                        model = imageUrl,
                        contentDescription = title,
                        modifier = Modifier.fillMaxSize(),
                        contentScale = ContentScale.Crop,
                    )
                } else {
                    Box(
                        modifier = Modifier
                            .fillMaxSize()
                            .background(
                                if (isOffer) Color(0xFF008040).copy(alpha = 0.12f)
                                else Color(0xFF1565C0).copy(alpha = 0.12f),
                            ),
                        contentAlignment = Alignment.Center,
                    ) {
                        Text(
                            title.take(2).uppercase(),
                            style = MaterialTheme.typography.titleMedium,
                            color = if (isOffer) Color(0xFF008040) else Color(0xFF1565C0),
                            fontWeight = FontWeight.Bold,
                        )
                    }
                }

                // Type badge overlay
                Surface(
                    modifier = Modifier
                        .align(Alignment.TopStart)
                        .padding(6.dp),
                    shape = RoundedCornerShape(4.dp),
                    color = if (isOffer) Color(0xFF008040) else Color(0xFF1565C0),
                ) {
                    Text(
                        if (isOffer) "OFFER" else "WANTED",
                        modifier = Modifier.padding(horizontal = 4.dp, vertical = 1.dp),
                        style = MaterialTheme.typography.labelSmall.copy(fontSize = MaterialTheme.typography.labelSmall.fontSize * 0.85),
                        color = Color.White,
                        fontWeight = FontWeight.Bold,
                    )
                }
            }

            // Title
            Text(
                title,
                modifier = Modifier.padding(horizontal = 8.dp, vertical = 6.dp),
                style = MaterialTheme.typography.bodySmall,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
                fontWeight = FontWeight.Medium,
            )
        }
    }
}

@Composable
private fun SearchResultsList(
    results: List<MessageSummary>,
    query: String,
    onMessageClick: (Long) -> Unit,
) {
    if (results.isEmpty()) {
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .padding(32.dp),
            contentAlignment = Alignment.Center,
        ) {
            Text(
                "No results for \u201c$query\u201d",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    } else {
        LazyColumn(modifier = Modifier.fillMaxSize()) {
            items(results) { msg ->
                ListItem(
                    headlineContent = {
                        Text(cleanTitle(msg.subject ?: "Item"), maxLines = 1, style = MaterialTheme.typography.bodyMedium)
                    },
                    supportingContent = {
                        Text(msg.location?.areaname ?: "", style = MaterialTheme.typography.bodySmall)
                    },
                    leadingContent = {
                        Surface(
                            shape = RoundedCornerShape(4.dp),
                            color = if (msg.type == "Offer") Color(0xFF008040) else Color(0xFF1565C0),
                        ) {
                            Text(
                                if (msg.type == "Offer") "OFFER" else "WANTED",
                                modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp),
                                style = MaterialTheme.typography.labelSmall,
                                color = Color.White,
                            )
                        }
                    },
                    modifier = Modifier.clickable { onMessageClick(msg.id) },
                )
                HorizontalDivider()
            }
        }
    }
}

@Composable
private fun LocationEmptyState(
    isDetecting: Boolean,
    onDetectLocation: () -> Unit,
    onSetPostcode: () -> Unit,
) {
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
                if (isDetecting) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(48.dp),
                        color = MaterialTheme.colorScheme.primary,
                    )
                } else {
                    Icon(
                        Icons.Default.LocationOn,
                        contentDescription = null,
                        modifier = Modifier.size(60.dp),
                        tint = MaterialTheme.colorScheme.primary,
                    )
                }
            }
            Spacer(Modifier.height(32.dp))
            Text(
                if (isDetecting) "Finding your location..." else "What's free nearby?",
                style = MaterialTheme.typography.headlineMedium,
                fontWeight = FontWeight.Bold,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(12.dp))
            Text(
                if (isDetecting) "We're detecting your location so we can show you free items nearby."
                else "Items are being given away near you right now. Browse what your neighbours are offering.",
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(32.dp))
            if (!isDetecting) {
                Button(
                    onClick = onDetectLocation,
                    modifier = Modifier
                        .fillMaxWidth(0.75f)
                        .height(52.dp),
                ) {
                    Icon(Icons.Default.LocationOn, contentDescription = null)
                    Spacer(Modifier.width(8.dp))
                    Text("Find items near me", style = MaterialTheme.typography.titleSmall)
                }
                Spacer(Modifier.height(12.dp))
                TextButton(onClick = onSetPostcode) {
                    Text("Enter postcode instead")
                }
            }
        }
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

/**
 * Attempts GPS-based location detection, reverse-geocodes to a postcode via the API,
 * and calls [onResult] with the resolved location data.
 */
@Suppress("MissingPermission") // Caller checks permission before invoking
private suspend fun detectAndSetLocation(
    context: android.content.Context,
    api: FreegleApi,
    prefs: FreeglePreferences,
    onResult: (postcode: String, locationName: String, lat: Double, lng: Double) -> Unit,
) {
    try {
        val locationManager = context.getSystemService(android.content.Context.LOCATION_SERVICE) as LocationManager
        // Try GPS first, then network
        val lastLocation = locationManager.getLastKnownLocation(LocationManager.GPS_PROVIDER)
            ?: locationManager.getLastKnownLocation(LocationManager.NETWORK_PROVIDER)

        if (lastLocation != null) {
            val response = api.resolveLocation(lastLocation.latitude, lastLocation.longitude)
            val loc = response?.location ?: response?.locations?.firstOrNull()
            if (loc != null) {
                val pc = loc.name ?: ""
                val name = loc.areaname ?: loc.area?.name ?: ""
                prefs.saveLocation(pc, name, loc.lat, loc.lng)
                onResult(pc, name, loc.lat, loc.lng)
                return
            }
        }
        // No GPS fix or API couldn't resolve - callback with empty to show manual entry
        onResult("", "", 0.0, 0.0)
    } catch (_: Exception) {
        onResult("", "", 0.0, 0.0)
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
