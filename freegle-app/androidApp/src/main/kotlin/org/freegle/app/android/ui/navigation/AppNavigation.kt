package org.freegle.app.android.ui.navigation

import androidx.compose.animation.core.*
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Chat
import androidx.compose.material.icons.automirrored.outlined.Chat
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavType
import androidx.navigation.compose.*
import androidx.navigation.navArgument
import org.freegle.app.android.ui.screens.*
import org.freegle.app.repository.ChatRepository
import org.koin.compose.koinInject

sealed class Screen(val route: String) {
    data object Home : Screen("home")
    data object Give : Screen("give")
    data object ChatList : Screen("chat_list")
    data object Profile : Screen("profile")
    data object PostDetail : Screen("post/{messageId}") {
        fun createRoute(messageId: Long) = "post/$messageId"
    }
    data object Chat : Screen("chat/{chatId}") {
        fun createRoute(chatId: Long) = "chat/$chatId"
    }
    data object Login : Screen("login")
}

data class BottomNavItem(
    val screen: Screen,
    val label: String,
    val selectedIcon: ImageVector,
    val unselectedIcon: ImageVector,
)

val bottomNavItems = listOf(
    BottomNavItem(Screen.Home, "Browse", Icons.Filled.Home, Icons.Outlined.Home),
    BottomNavItem(Screen.Give, "Give", Icons.Filled.Add, Icons.Filled.Add),
    BottomNavItem(Screen.ChatList, "Chat", Icons.AutoMirrored.Filled.Chat, Icons.AutoMirrored.Outlined.Chat),
    BottomNavItem(Screen.Profile, "Me", Icons.Filled.Person, Icons.Outlined.Person),
)

@Composable
fun FreegleNavHost(
    chatRepository: ChatRepository = koinInject(),
) {
    val navController = rememberNavController()
    val navBackStackEntry by navController.currentBackStackEntryAsState()
    val currentDestination = navBackStackEntry?.destination

    val chatRooms by chatRepository.chatRooms.collectAsState()
    val totalUnread = chatRooms.sumOf { it.unseen }.coerceAtMost(99)

    val showBottomBar = bottomNavItems.any { item ->
        currentDestination?.hierarchy?.any { it.route == item.screen.route } == true
    }

    // Pulsing animation for the Give button
    val pulseAnim = rememberInfiniteTransition(label = "give_pulse")
    val pulseScale by pulseAnim.animateFloat(
        initialValue = 1f,
        targetValue = 1.12f,
        animationSpec = infiniteRepeatable(
            animation = tween(900, easing = FastOutSlowInEasing),
            repeatMode = RepeatMode.Reverse,
        ),
        label = "pulse_scale",
    )

    Scaffold(
        bottomBar = {
            if (showBottomBar) {
                NavigationBar(
                    containerColor = MaterialTheme.colorScheme.surface,
                    tonalElevation = 8.dp,
                ) {
                    bottomNavItems.forEach { item ->
                        val selected = currentDestination?.hierarchy?.any {
                            it.route == item.screen.route
                        } == true

                        if (item.screen == Screen.Give) {
                            // Special Give button
                            NavigationBarItem(
                                selected = selected,
                                onClick = {
                                    navController.navigate(item.screen.route) {
                                        popUpTo(navController.graph.findStartDestination().id) {
                                            saveState = true
                                        }
                                        launchSingleTop = true
                                        restoreState = true
                                    }
                                },
                                icon = {
                                    Box(
                                        modifier = Modifier
                                            .size(52.dp)
                                            .scale(if (!selected) pulseScale else 1f)
                                            .background(
                                                color = MaterialTheme.colorScheme.primary,
                                                shape = CircleShape,
                                            ),
                                        contentAlignment = Alignment.Center,
                                    ) {
                                        Icon(
                                            imageVector = Icons.Filled.Add,
                                            contentDescription = "Give something away",
                                            tint = MaterialTheme.colorScheme.onPrimary,
                                            modifier = Modifier.size(26.dp),
                                        )
                                    }
                                },
                                label = {
                                    Text(
                                        "Give",
                                        fontWeight = FontWeight.SemiBold,
                                        color = if (selected) MaterialTheme.colorScheme.primary
                                        else MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                },
                                colors = NavigationBarItemDefaults.colors(
                                    indicatorColor = Color.Transparent,
                                ),
                            )
                        } else {
                            NavigationBarItem(
                                selected = selected,
                                onClick = {
                                    navController.navigate(item.screen.route) {
                                        popUpTo(navController.graph.findStartDestination().id) {
                                            saveState = true
                                        }
                                        launchSingleTop = true
                                        restoreState = true
                                    }
                                },
                                icon = {
                                    if (item.screen == Screen.ChatList && totalUnread > 0) {
                                        BadgedBox(
                                            badge = {
                                                Badge { Text(totalUnread.toString()) }
                                            },
                                        ) {
                                            Icon(
                                                imageVector = if (selected) item.selectedIcon else item.unselectedIcon,
                                                contentDescription = item.label,
                                            )
                                        }
                                    } else {
                                        Icon(
                                            imageVector = if (selected) item.selectedIcon else item.unselectedIcon,
                                            contentDescription = item.label,
                                        )
                                    }
                                },
                                label = { Text(item.label) },
                            )
                        }
                    }
                }
            }
        },
    ) { innerPadding ->
        NavHost(
            navController = navController,
            startDestination = Screen.Home.route,
            modifier = Modifier.padding(innerPadding),
        ) {
            composable(Screen.Home.route) {
                HomeScreen(
                    onMessageClick = { messageId ->
                        navController.navigate(Screen.PostDetail.createRoute(messageId))
                    },
                    onPostWantedClick = {
                        navController.navigate(Screen.Give.route) {
                            popUpTo(navController.graph.findStartDestination().id) {
                                saveState = true
                            }
                            launchSingleTop = true
                            restoreState = true
                        }
                    },
                )
            }
            composable(Screen.Give.route) {
                GiveScreen()
            }
            composable(Screen.ChatList.route) {
                ChatListScreen(
                    onChatClick = { chatId ->
                        navController.navigate(Screen.Chat.createRoute(chatId))
                    },
                    onBrowseClick = {
                        navController.navigate(Screen.Home.route) {
                            popUpTo(navController.graph.findStartDestination().id) {
                                saveState = true
                            }
                            launchSingleTop = true
                            restoreState = true
                        }
                    },
                )
            }
            composable(Screen.Profile.route) {
                ProfileScreen(
                    onLoginClick = {
                        navController.navigate(Screen.Login.route)
                    },
                )
            }
            composable(
                route = Screen.PostDetail.route,
                arguments = listOf(navArgument("messageId") { type = NavType.LongType }),
            ) { backStackEntry ->
                val messageId = backStackEntry.arguments?.getLong("messageId") ?: return@composable
                PostDetailScreen(
                    messageId = messageId,
                    onBack = { navController.popBackStack() },
                    onChatClick = { chatId ->
                        navController.navigate(Screen.Chat.createRoute(chatId))
                    },
                )
            }
            composable(
                route = Screen.Chat.route,
                arguments = listOf(navArgument("chatId") { type = NavType.LongType }),
            ) { backStackEntry ->
                val chatId = backStackEntry.arguments?.getLong("chatId") ?: return@composable
                ChatScreen(
                    chatId = chatId,
                    onBack = { navController.popBackStack() },
                )
            }
            composable(Screen.Login.route) {
                LoginScreen(
                    onLoginSuccess = { navController.popBackStack() },
                )
            }
        }
    }
}
