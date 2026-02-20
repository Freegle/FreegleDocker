package org.freegle.app.android.ui.screens

import androidx.compose.animation.core.*
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Login
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil3.compose.AsyncImage
import org.freegle.app.repository.UserRepository
import org.koin.compose.koinInject

@Composable
fun ProfileScreen(
    onLoginClick: () -> Unit,
    userRepository: UserRepository = koinInject(),
) {
    val user by userRepository.currentUser.collectAsState()
    val isLoggedIn = userRepository.isLoggedIn

    LaunchedEffect(isLoggedIn) {
        if (isLoggedIn) {
            userRepository.loadCurrentUser()
        }
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .background(MaterialTheme.colorScheme.background),
    ) {
        if (!isLoggedIn) {
            NotLoggedInContent(onLoginClick = onLoginClick)
        } else {
            val offers = user?.info?.offers ?: 0
            val collected = user?.info?.collected ?: 0
            LoggedInContent(
                displayName = user?.displayname ?: "Freegler",
                avatarUrl = user?.profile?.url,
                offers = offers,
                collected = collected,
                onLogout = { userRepository.logout() },
            )
        }
    }
}

@Composable
private fun NotLoggedInContent(onLoginClick: () -> Unit) {
    // Gradient hero
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .height(280.dp)
            .background(
                Brush.verticalGradient(
                    colors = listOf(
                        Color(0xFF003318),
                        Color(0xFF00B050),
                    ),
                ),
            ),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            modifier = Modifier.padding(32.dp),
        ) {
            Box(
                modifier = Modifier
                    .size(80.dp)
                    .clip(CircleShape)
                    .background(Color.White.copy(alpha = 0.2f)),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    Icons.Default.Person,
                    contentDescription = null,
                    modifier = Modifier.size(48.dp),
                    tint = Color.White,
                )
            }
            Spacer(Modifier.height(16.dp))
            Text(
                "Welcome to Freegle",
                style = MaterialTheme.typography.headlineSmall,
                fontWeight = FontWeight.Bold,
                color = Color.White,
            )
            Spacer(Modifier.height(6.dp))
            Text(
                "Sign in to give, chat, and join your local community",
                style = MaterialTheme.typography.bodyMedium,
                color = Color.White.copy(alpha = 0.8f),
                textAlign = TextAlign.Center,
            )
        }
    }

    Column(modifier = Modifier.padding(24.dp)) {
        Button(
            onClick = onLoginClick,
            modifier = Modifier
                .fillMaxWidth()
                .height(52.dp),
            shape = RoundedCornerShape(16.dp),
        ) {
            Icon(Icons.AutoMirrored.Filled.Login, contentDescription = null)
            Spacer(Modifier.width(8.dp))
            Text("Sign in", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold)
        }

        Spacer(Modifier.height(24.dp))

        // Community teaser
        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(20.dp),
            colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
        ) {
            Column(modifier = Modifier.padding(20.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("🌍", style = MaterialTheme.typography.titleLarge)
                    Spacer(Modifier.width(10.dp))
                    Text(
                        "Join your local community",
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                        color = MaterialTheme.colorScheme.onPrimaryContainer,
                    )
                }
                Spacer(Modifier.height(8.dp))
                Text(
                    "Freeglers across the UK are giving and receiving items every day.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onPrimaryContainer.copy(alpha = 0.8f),
                )
            }
        }
    }
}

@Composable
private fun LoggedInContent(
    displayName: String,
    avatarUrl: String?,
    offers: Int,
    collected: Int,
    onLogout: () -> Unit,
) {
    // Animated stats
    val animatedOffers = remember { Animatable(0f) }
    val animatedCollected = remember { Animatable(0f) }
    val estimatedKg = (offers * 1.5f).toInt()
    val animatedKg = remember { Animatable(0f) }

    LaunchedEffect(offers, collected) {
        animatedOffers.animateTo(offers.toFloat(), tween(1200, easing = FastOutSlowInEasing))
        animatedCollected.animateTo(collected.toFloat(), tween(1200, easing = FastOutSlowInEasing))
        animatedKg.animateTo(estimatedKg.toFloat(), tween(1500, easing = FastOutSlowInEasing))
    }

    // Hero gradient header
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .height(220.dp)
            .background(
                Brush.verticalGradient(
                    colors = listOf(Color(0xFF003318), Color(0xFF00B050)),
                ),
            ),
    ) {
        // Decorative circles
        Box(
            modifier = Modifier
                .size(200.dp)
                .offset(x = (-40).dp, y = (-60).dp)
                .clip(CircleShape)
                .background(Color.White.copy(alpha = 0.04f)),
        )
        Box(
            modifier = Modifier
                .size(140.dp)
                .align(Alignment.TopEnd)
                .offset(x = 40.dp, y = (-20).dp)
                .clip(CircleShape)
                .background(Color.White.copy(alpha = 0.06f)),
        )

        // Avatar + name
        Column(
            modifier = Modifier
                .align(Alignment.BottomStart)
                .padding(24.dp),
        ) {
            if (avatarUrl != null) {
                AsyncImage(
                    model = avatarUrl,
                    contentDescription = "Avatar",
                    modifier = Modifier
                        .size(68.dp)
                        .clip(CircleShape),
                )
            } else {
                Box(
                    modifier = Modifier
                        .size(68.dp)
                        .clip(CircleShape)
                        .background(Color.White.copy(alpha = 0.2f)),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(
                        text = displayName.firstOrNull()?.uppercase() ?: "F",
                        style = MaterialTheme.typography.headlineMedium,
                        color = Color.White,
                        fontWeight = FontWeight.Bold,
                    )
                }
            }
            Spacer(Modifier.height(10.dp))
            Text(
                text = displayName,
                style = MaterialTheme.typography.headlineSmall,
                fontWeight = FontWeight.Bold,
                color = Color.White,
            )
            Text(
                text = "Freegler",
                style = MaterialTheme.typography.bodyMedium,
                color = Color.White.copy(alpha = 0.75f),
            )
        }
    }

    // Impact stats
    Column(modifier = Modifier.padding(20.dp)) {
        Text(
            "Your impact",
            style = MaterialTheme.typography.titleLarge,
            fontWeight = FontWeight.Bold,
        )
        Spacer(Modifier.height(4.dp))
        Text(
            "Real difference in your community",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Spacer(Modifier.height(16.dp))

        // Impact cards row
        Row(
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            modifier = Modifier.fillMaxWidth(),
        ) {
            ImpactCard(
                modifier = Modifier.weight(1f),
                emoji = "🎁",
                value = animatedOffers.value.toInt().toString(),
                label = "Items given",
                containerColor = MaterialTheme.colorScheme.primaryContainer,
                contentColor = MaterialTheme.colorScheme.onPrimaryContainer,
            )
            ImpactCard(
                modifier = Modifier.weight(1f),
                emoji = "🤝",
                value = animatedCollected.value.toInt().toString(),
                label = "Items received",
                containerColor = MaterialTheme.colorScheme.tertiaryContainer,
                contentColor = MaterialTheme.colorScheme.onTertiaryContainer,
            )
        }

        Spacer(Modifier.height(12.dp))

        // Landfill card - full width, most impactful stat
        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(20.dp),
            colors = CardDefaults.cardColors(
                containerColor = Color(0xFF003318),
            ),
        ) {
            Row(
                modifier = Modifier.padding(20.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    text = "🌱",
                    style = MaterialTheme.typography.displaySmall,
                )
                Spacer(Modifier.width(16.dp))
                Column {
                    Row(verticalAlignment = Alignment.Bottom) {
                        Text(
                            text = animatedKg.value.toInt().toString(),
                            style = MaterialTheme.typography.displaySmall,
                            fontWeight = FontWeight.Black,
                            color = Color(0xFF4CD681),
                        )
                        Text(
                            text = " kg",
                            style = MaterialTheme.typography.titleLarge,
                            fontWeight = FontWeight.Bold,
                            color = Color(0xFF4CD681),
                        )
                    }
                    Text(
                        text = "saved from landfill",
                        style = MaterialTheme.typography.bodyMedium,
                        color = Color.White.copy(alpha = 0.8f),
                    )
                }
            }
        }

        Spacer(Modifier.height(24.dp))

        // Achievements
        if (offers > 0 || collected > 0) {
            Text(
                "Milestones",
                style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.Bold,
            )
            Spacer(Modifier.height(12.dp))

            val achievements = buildAchievements(offers = offers, collected = collected)
            achievements.forEach { achievement ->
                AchievementRow(achievement)
                Spacer(Modifier.height(8.dp))
            }

            Spacer(Modifier.height(8.dp))
        }

        // Settings section
        Text(
            "Account",
            style = MaterialTheme.typography.titleLarge,
            fontWeight = FontWeight.Bold,
        )
        Spacer(Modifier.height(12.dp))

        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(20.dp),
        ) {
            Column {
                SettingsRow(emoji = "🔔", label = "Notifications") {}
                HorizontalDivider(modifier = Modifier.padding(horizontal = 16.dp))
                SettingsRow(emoji = "⚙️", label = "Settings") {}
                HorizontalDivider(modifier = Modifier.padding(horizontal = 16.dp))
                SettingsRow(
                    emoji = "🚪",
                    label = "Sign out",
                    isDestructive = true,
                    onClick = onLogout,
                )
            }
        }

        Spacer(Modifier.height(20.dp))

        Text(
            "Freegle — Don't throw it away, give it away!",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center,
            modifier = Modifier.fillMaxWidth(),
        )

        Spacer(Modifier.height(20.dp))
    }
}

@Composable
private fun ImpactCard(
    modifier: Modifier = Modifier,
    emoji: String,
    value: String,
    label: String,
    containerColor: Color,
    contentColor: Color,
) {
    Card(
        modifier = modifier,
        shape = RoundedCornerShape(20.dp),
        colors = CardDefaults.cardColors(containerColor = containerColor),
    ) {
        Column(
            modifier = Modifier.padding(16.dp),
        ) {
            Text(text = emoji, style = MaterialTheme.typography.titleLarge)
            Spacer(Modifier.height(6.dp))
            Text(
                text = value,
                style = MaterialTheme.typography.displaySmall,
                fontWeight = FontWeight.Black,
                color = contentColor,
                fontSize = 32.sp,
            )
            Text(
                text = label,
                style = MaterialTheme.typography.bodySmall,
                color = contentColor.copy(alpha = 0.75f),
            )
        }
    }
}

private data class Achievement(val emoji: String, val title: String, val description: String, val earned: Boolean)

private fun buildAchievements(offers: Int, collected: Int): List<Achievement> = buildList {
    add(Achievement("🌟", "First give", "Gave away your first item", offers >= 1))
    add(Achievement("🎯", "Generous five", "Gave away 5 items", offers >= 5))
    add(Achievement("🏆", "Community hero", "Gave away 10 items", offers >= 10))
    add(Achievement("🤝", "First collect", "Received your first free item", collected >= 1))
}.filter { it.earned }

@Composable
private fun AchievementRow(achievement: Achievement) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(16.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant),
    ) {
        Row(
            modifier = Modifier.padding(16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(achievement.emoji, style = MaterialTheme.typography.titleLarge)
            Spacer(Modifier.width(14.dp))
            Column {
                Text(
                    achievement.title,
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                )
                Text(
                    achievement.description,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            Spacer(Modifier.weight(1f))
            Icon(
                Icons.Default.CheckCircle,
                contentDescription = "Earned",
                tint = MaterialTheme.colorScheme.primary,
                modifier = Modifier.size(22.dp),
            )
        }
    }
}

@Composable
private fun SettingsRow(
    emoji: String,
    label: String,
    isDestructive: Boolean = false,
    onClick: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(horizontal = 20.dp, vertical = 16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(emoji, style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.width(14.dp))
        Text(
            text = label,
            style = MaterialTheme.typography.bodyLarge,
            color = if (isDestructive) MaterialTheme.colorScheme.error
            else MaterialTheme.colorScheme.onSurface,
            modifier = Modifier.weight(1f),
        )
        Icon(
            Icons.Default.ChevronRight,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.size(20.dp),
        )
    }
}
