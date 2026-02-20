package org.freegle.app.android.ui.navigation

import androidx.compose.animation.core.*
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Chat
import androidx.compose.material.icons.automirrored.outlined.Chat
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Explore
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.outlined.Explore
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
import kotlinx.coroutines.launch
import org.freegle.app.android.data.FreeglePreferences
import org.freegle.app.android.ui.components.FreegleHeartArrows
import org.freegle.app.android.ui.screens.*
import org.freegle.app.repository.ChatRepository
import org.koin.compose.koinInject

sealed class Screen(val route: String) {
    data object Home : Screen("home")
    data object Explore : Screen("explore")
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

// 5-tab layout with Give centred: Home | Explore | Give | Chat | Me
val bottomNavItems = listOf(
    BottomNavItem(Screen.Home, "Home", Icons.Filled.Home, Icons.Outlined.Home),
    BottomNavItem(Screen.Explore, "Explore", Icons.Filled.Explore, Icons.Outlined.Explore),
    BottomNavItem(Screen.Give, "Give", Icons.Filled.Add, Icons.Filled.Add),
    BottomNavItem(Screen.ChatList, "Chat", Icons.AutoMirrored.Filled.Chat, Icons.AutoMirrored.Outlined.Chat),
    BottomNavItem(Screen.Profile, "Me", Icons.Filled.Person, Icons.Outlined.Person),
)

@Composable
fun FreegleNavHost(
    chatRepository: ChatRepository = koinInject(),
    prefs: FreeglePreferences = koinInject(),
) {
    val navController = rememberNavController()
    val navBackStackEntry by navController.currentBackStackEntryAsState()
    val currentDestination = navBackStackEntry?.destination
    val scope = rememberCoroutineScope()

    val chatRooms by chatRepository.chatRooms.collectAsState()
    val totalUnread = chatRooms.sumOf { it.unseen }.coerceAtMost(99)

    // Onboarding state
    var showOnboarding by remember { mutableStateOf<Boolean?>(null) }
    var tourStep by remember { mutableIntStateOf(-1) } // -1 = no tour
    LaunchedEffect(Unit) {
        showOnboarding = !prefs.isOnboardingComplete()
    }

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

    // Show onboarding on first launch
    if (showOnboarding == null) {
        // Still loading preference
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            CircularProgressIndicator()
        }
        return
    }
    if (showOnboarding == true) {
        OnboardingScreen(
            onComplete = {
                scope.launch { prefs.setOnboardingComplete() }
                showOnboarding = false
                tourStep = 0 // Start feature tour after onboarding
            },
        )
        return
    }

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
                                        // Heart-shaped recycling arrows from Freegle logo
                                        FreegleHeartArrows(
                                            modifier = Modifier.size(34.dp),
                                            color = MaterialTheme.colorScheme.onPrimary,
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
        Box(modifier = Modifier.fillMaxSize().padding(innerPadding)) {
        NavHost(
            navController = navController,
            startDestination = Screen.Home.route,
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
            composable(Screen.Explore.route) {
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
                var postcode by remember { mutableStateOf("") }
                var locationName by remember { mutableStateOf("") }
                LaunchedEffect(Unit) {
                    postcode = prefs.getPostcode()
                    locationName = prefs.getLocationName()
                }
                GiveScreen(
                    userPostcode = postcode,
                    userLocationName = locationName,
                )
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

        // Feature tour overlay (z-ordered above NavHost inside the Box)
        if (tourStep >= 0) {
            FeatureTourOverlay(
                currentStep = tourStep,
                onNext = {
                    tourStep++
                    if (tourStep >= 5) {
                        tourStep = -1
                        scope.launch { prefs.setTourComplete() }
                    }
                },
                onSkip = {
                    tourStep = -1
                    scope.launch { prefs.setTourComplete() }
                },
            )
        }
        } // end Box
    }
}
