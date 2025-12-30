# AMP Email Implementation Plan

## Current Status (December 2024)

### ✅ Completed

| Component | Status | Notes |
|-----------|--------|-------|
| **Go API - AMP Endpoints** | ✅ Done | `/amp/chat/:id` (GET), `/amp/chat/:id/reply` (POST) |
| **Go API - Token Validation** | ✅ Done | Read tokens (HMAC), write tokens (one-time DB nonce) |
| **Go API - CORS Middleware** | ✅ Done | Supports both v1 and v2 AMP CORS flows |
| **Laravel - AmpEmail Trait** | ✅ Done | Token generation, MIME structure |
| **Laravel - AmpWriteToken Model** | ✅ Done | One-time use tokens with DB storage |
| **Laravel - ChatNotification AMP** | ✅ Done | AMP template for chat notifications |
| **Loki Logging** | ✅ Done | Reply source tracking (amp/email/website) |
| **Email Header Capture** | ✅ Done | Generic header capture including Reply-To |
| **Database Migration** | ✅ Done | `amp_write_tokens` table created |

### ⏳ Pending

| Component | Status | Notes |
|-----------|--------|-------|
| **Google Sender Registration** | ❌ Not started | See "Important Note" below |
| **Yahoo Sender Registration** | ❌ Not started | Same registration form as Google |
| **Jobs AMP Template** | ❌ Not started | Dynamic job listings in emails |
| **Digest AMP Support** | ❌ Not started | AMP for digest emails |
| **Production Rollout** | ❌ Not started | Waiting on sender registration |

### ⚠️ Important Note: Sender Registration Required

**AMP emails will NOT render for end users until we complete sender registration with Google and Yahoo.**

Currently:
- AMP functionality is fully implemented in code
- Emails are being sent with AMP MIME parts
- **However**, Gmail and Yahoo will strip the AMP content and show only the HTML fallback until we are registered

To enable AMP for users, we must:
1. Complete the [Google AMP for Email Sender Registration](https://docs.google.com/forms/d/e/1FAIpQLSdso95e7UDLk_R-bnpzsAmuUMDQEMUgTErcfGGItBDkghHU2A/viewform)
2. Meet Google's requirements (SPF/DKIM/DMARC in place for 3+ months, consistent sending history)
3. Wait for approval (typically 5 working days, up to 2 weeks to take effect)

For **testing purposes**, developers can enable Gmail Developer Mode (see "Testing Before Whitelisting" section).

---

## Overview

This plan outlines the implementation of AMP (Accelerated Mobile Pages) for Email across Freegle's notification system. AMP enables dynamic, interactive email content that can fetch fresh data when opened and allow inline actions like replying to chats.

## Key Features

### Chat Notifications
- **Dynamic message list**: Show new messages that arrived since the notification was sent
- **Inline reply**: Users can type and send replies directly from the email
- **Real-time status**: Show if item is still available or has been marked TAKEN
- **Read receipts**: Display when messages were read

### Job Ads
- **Fresh listings**: Fetch current jobs when email is opened (not stale jobs from send time)
- **Availability status**: Only show jobs that are still open
- **Personalisation**: Show jobs relevant to user's recent activity

### Open Rate Tracking
- **AMP-based tracking**: More reliable than pixel tracking for AMP-capable clients
- **Graceful fallback**: Traditional pixel for non-AMP clients

---

## Email Client Support

| Client | AMP Support | Fallback |
|--------|-------------|----------|
| Gmail | Full | HTML |
| Yahoo Mail | Full | HTML |
| Mail.ru | Full | HTML |
| Outlook | None | HTML only |
| Apple Mail | None | HTML only |
| Thunderbird | None | HTML only |

**Expected coverage**: ~40-50% of users will see AMP content (primarily Gmail users).

---

## Architecture

### Component Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                         Email Flow                               │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  iznik-batch (Laravel)              iznik-server-go (API)       │
│  ┌─────────────────────┐            ┌─────────────────────┐     │
│  │ ChatNotification    │            │ /amp/chat/:id       │     │
│  │ Mailable            │            │ - GET messages      │     │
│  │                     │            │ - POST reply        │     │
│  │ - HTML (MJML)       │◄──────────►│                     │     │
│  │ - AMP version       │  fetch     │ /amp/jobs           │     │
│  │ - Text fallback     │            │ - GET fresh jobs    │     │
│  │                     │            │                     │     │
│  │ Uses AmpEmail trait │            │ /amp/track/:id      │     │
│  └─────────────────────┘            │ - GET (open track)  │     │
│                                     └─────────────────────┘     │
│                                                                  │
│  Email Structure (MIME):                                         │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ multipart/alternative                                    │    │
│  │ ├── text/plain (fallback)                               │    │
│  │ ├── text/x-amp-html (AMP version) ◄── Rendered first    │    │
│  │ └── text/html (MJML compiled)                           │    │
│  └─────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
```

### Token-Based Authentication

Since AMP requests are proxied by email clients and cookies are stripped, we use secure tokens.

**IMPORTANT SECURITY CONSIDERATION**: If a user forwards an email, the recipient could potentially use the tokens. While Gmail strips AMP content on forward, other clients may not. We therefore use **separate tokens for read and write operations**, with write tokens being **one-time use**.

#### Read Tokens (for amp-list - viewing content)

```
Read Token Generation:
┌─────────────────────────────────────────────────────────────────┐
│ read_token = HMAC-SHA256(                                       │
│   secret_key,                                                   │
│   "read" + user_id + resource_id + expiry_timestamp             │
│ )                                                               │
│                                                                  │
│ URL: /amp/chat/123?rt=xxx&uid=456&exp=1234567890               │
└─────────────────────────────────────────────────────────────────┘

- Allows: Viewing messages, viewing jobs
- Reusable: Can be used multiple times within expiry
- Risk if forwarded: Privacy (can see conversation user is party to)
- Mitigation: 31-day expiry, limited scope
```

#### Write Tokens (for amp-form - replying)

```
Write Token Generation:
┌─────────────────────────────────────────────────────────────────┐
│ 1. Generate random nonce (32 bytes, hex encoded)                │
│ 2. Store in database: amp_write_tokens table                    │
│    - nonce, user_id, chat_id, email_tracking_id, expires_at     │
│ 3. Include nonce in URL (no HMAC needed - DB lookup)            │
│                                                                  │
│ URL: /amp/chat/123/reply?wt=<nonce>                             │
└─────────────────────────────────────────────────────────────────┘

Write Token Validation:
┌─────────────────────────────────────────────────────────────────┐
│ 1. Look up nonce in amp_write_tokens table                      │
│ 2. Check: used_at IS NULL (not already used)                    │
│ 3. Check: expires_at > NOW() (not expired)                      │
│ 4. Check: chat_id matches request                               │
│ 5. IMMEDIATELY mark as used: SET used_at = NOW()                │
│ 6. Return user_id for the reply                                 │
│ 7. If any check fails: return error, don't allow reply          │
└─────────────────────────────────────────────────────────────────┘

- Allows: Posting ONE reply only
- One-time use: Invalidated after first successful use
- Risk if forwarded: Attacker could post ONE message as user
- Mitigation: One-time use, message appears in chat (visible to user)
```

#### Why Separate Tokens?

| Scenario | Read Token | Write Token |
|----------|------------|-------------|
| Normal use | User views messages | User replies |
| Email forwarded | Forwardee can view (privacy leak) | Forwardee can send ONE message |
| Token reuse | Allowed (within expiry) | Blocked after first use |
| Token collision | ~0 (HMAC-SHA256) | ~0 (random nonce + DB lookup) |

---

## Implementation Phases

### Phase 1: Infrastructure (Foundation)

#### 1.1 Go API - AMP Endpoints

**File**: `iznik-server-go/amp/tokens.go`

```go
package amp

import (
    "crypto/hmac"
    "crypto/rand"
    "crypto/sha256"
    "crypto/subtle"
    "encoding/hex"
    "strconv"
    "time"

    "github.com/freegle/iznik-server-go/database"
    "github.com/gofiber/fiber/v2"
)

// ValidateReadToken validates HMAC-based read tokens for amp-list
// Read tokens are reusable within their expiry period
func ValidateReadToken(c *fiber.Ctx) (userID uint64, resourceID uint64, err error) {
    token := c.Query("rt")  // Read token
    uid := c.Query("uid")
    exp := c.Query("exp")
    resID := c.Params("id")

    // Check expiry
    expTime, err := strconv.ParseInt(exp, 10, 64)
    if err != nil || time.Now().Unix() > expTime {
        return 0, 0, nil // Return zero values, not error - graceful fallback
    }

    // Validate HMAC: "read" + user_id + resource_id + expiry
    secret := config.GetAMPSecret()
    message := "read" + uid + resID + exp
    expectedMAC := computeHMAC(message, secret)

    // Constant-time comparison to prevent timing attacks
    if subtle.ConstantTimeCompare([]byte(token), []byte(expectedMAC)) != 1 {
        return 0, 0, nil // Graceful fallback
    }

    userID, _ = strconv.ParseUint(uid, 10, 64)
    resourceID, _ = strconv.ParseUint(resID, 10, 64)

    // Verify user still exists
    var exists bool
    database.DBConn.Raw("SELECT EXISTS(SELECT 1 FROM users WHERE id = ?)", userID).Scan(&exists)
    if !exists {
        return 0, 0, nil
    }

    return userID, resourceID, nil
}

// ValidateWriteToken validates one-time-use write tokens for amp-form
// Write tokens can only be used ONCE and are stored in the database
func ValidateWriteToken(c *fiber.Ctx) (userID uint64, chatID uint64, err error) {
    nonce := c.Query("wt")  // Write token (nonce)
    reqChatID := c.Params("id")

    chatIDUint, _ := strconv.ParseUint(reqChatID, 10, 64)

    db := database.DBConn

    // Look up token with row locking to prevent race conditions
    var token struct {
        ID        uint64
        UserID    uint64
        ChatID    uint64
        UsedAt    *time.Time
        ExpiresAt time.Time
    }

    // Use FOR UPDATE to lock the row during validation
    db.Raw(`
        SELECT id, user_id, chat_id, used_at, expires_at
        FROM amp_write_tokens
        WHERE nonce = ?
        FOR UPDATE
    `, nonce).Scan(&token)

    // Check token exists
    if token.ID == 0 {
        return 0, 0, fiber.NewError(fiber.StatusUnauthorized, "Invalid token")
    }

    // Check not already used
    if token.UsedAt != nil {
        return 0, 0, fiber.NewError(fiber.StatusUnauthorized, "Token already used")
    }

    // Check not expired
    if time.Now().After(token.ExpiresAt) {
        return 0, 0, fiber.NewError(fiber.StatusUnauthorized, "Token expired")
    }

    // Check chat ID matches
    if token.ChatID != chatIDUint {
        return 0, 0, fiber.NewError(fiber.StatusUnauthorized, "Token mismatch")
    }

    // IMMEDIATELY mark as used - do this BEFORE any other operation
    result := db.Exec(`UPDATE amp_write_tokens SET used_at = NOW() WHERE id = ?`, token.ID)
    if result.RowsAffected == 0 {
        return 0, 0, fiber.NewError(fiber.StatusConflict, "Token already used")
    }

    return token.UserID, token.ChatID, nil
}

// GenerateWriteToken creates a new one-time-use write token
// Called from Laravel when generating the email
func GenerateWriteTokenNonce() string {
    bytes := make([]byte, 32)
    rand.Read(bytes)
    return hex.EncodeToString(bytes)
}

func computeHMAC(message, secret string) string {
    h := hmac.New(sha256.New, []byte(secret))
    h.Write([]byte(message))
    return hex.EncodeToString(h.Sum(nil))
}
```

**File**: `iznik-server-go/amp/cors.go`

```go
package amp

import "github.com/gofiber/fiber/v2"

// AMPCORSMiddleware handles both v1 and v2 AMP CORS requirements
func AMPCORSMiddleware() fiber.Handler {
    return func(c *fiber.Ctx) error {
        // Get AMP sender header (v2)
        ampSender := c.Get("AMP-Email-Sender")

        // Get Origin header (v1)
        origin := c.Get("Origin")
        sourceOrigin := c.Query("__amp_source_origin")

        if ampSender != "" {
            // Version 2: Just validate and echo back
            if !isAllowedSender(ampSender) {
                return fiber.NewError(fiber.StatusForbidden, "Sender not allowed")
            }
            c.Set("AMP-Email-Allow-Sender", ampSender)
        } else if origin != "" && sourceOrigin != "" {
            // Version 1: Full CORS headers
            if !isAllowedSender(sourceOrigin) {
                return fiber.NewError(fiber.StatusForbidden, "Sender not allowed")
            }
            c.Set("Access-Control-Allow-Origin", origin)
            c.Set("Access-Control-Expose-Headers", "AMP-Access-Control-Allow-Source-Origin")
            c.Set("AMP-Access-Control-Allow-Source-Origin", sourceOrigin)
        }

        // Handle preflight
        if c.Method() == "OPTIONS" {
            c.Set("Access-Control-Allow-Methods", "GET, POST")
            c.Set("Access-Control-Allow-Headers", "Content-Type, AMP-Email-Sender")
            return c.SendStatus(fiber.StatusNoContent)
        }

        return c.Next()
    }
}

func isAllowedSender(email string) bool {
    // Allow our sending domains
    allowedDomains := []string{
        "@ilovefreegle.org",
        "@users.ilovefreegle.org",
    }
    for _, domain := range allowedDomains {
        if strings.HasSuffix(email, domain) {
            return true
        }
    }
    return false
}
```

#### 1.2 Go API - Chat Endpoints

**File**: `iznik-server-go/amp/chat.go`

```go
package amp

import (
    "github.com/freegle/iznik-server-go/database"
    "github.com/gofiber/fiber/v2"
)

type ChatMessage struct {
    ID        uint64 `json:"id"`
    Message   string `json:"message"`
    FromUser  string `json:"fromUser"`
    FromImage string `json:"fromImage"`
    Date      string `json:"date"`
    IsNew     bool   `json:"isNew"`
    IsMine    bool   `json:"isMine"`
}

type ChatResponse struct {
    Items         []ChatMessage `json:"items"`
    ChatID        uint64        `json:"chatId"`
    OtherUserName string        `json:"otherUserName"`
    ItemSubject   string        `json:"itemSubject,omitempty"`
    ItemAvailable bool          `json:"itemAvailable"`
    CanReply      bool          `json:"canReply"`
}

// GetChatMessages returns messages for AMP email dynamic content
func GetChatMessages(c *fiber.Ctx) error {
    userID, chatID, err := ValidateAMPToken(c)
    if err != nil {
        // Return graceful fallback response
        return c.JSON(ChatResponse{
            Items:    []ChatMessage{},
            CanReply: false,
        })
    }

    // Get the "since" parameter - messages newer than this ID are marked as new
    sinceID := c.QueryInt("since", 0)

    db := database.DBConn

    // Verify user is member of this chat
    var membership struct {
        UserID uint64
    }
    db.Raw(`
        SELECT userid FROM chat_roster
        WHERE chatid = ? AND userid = ?
    `, chatID, userID).Scan(&membership)

    if membership.UserID == 0 {
        return c.JSON(ChatResponse{Items: []ChatMessage{}, CanReply: false})
    }

    // Fetch messages (last 31 days, max 50)
    var messages []struct {
        ID          uint64
        Message     string
        UserID      uint64
        DisplayName string
        ProfileURL  string
        Date        string
    }

    db.Raw(`
        SELECT
            cm.id,
            cm.message,
            cm.userid,
            COALESCE(u.displayname, u.fullname, 'A Freegler') as displayname,
            u.profile as profileurl,
            DATE_FORMAT(cm.date, '%Y-%m-%dT%H:%i:%s') as date
        FROM chat_messages cm
        LEFT JOIN users u ON u.id = cm.userid
        WHERE cm.chatid = ?
          AND cm.date > DATE_SUB(NOW(), INTERVAL 31 DAY)
          AND cm.reviewrequired = 0
          AND cm.processingsuccessful = 1
        ORDER BY cm.date DESC
        LIMIT 50
    `, chatID).Scan(&messages)

    // Build response
    items := make([]ChatMessage, len(messages))
    for i, m := range messages {
        items[i] = ChatMessage{
            ID:        m.ID,
            Message:   m.Message,
            FromUser:  m.DisplayName,
            FromImage: m.ProfileURL,
            Date:      m.Date,
            IsNew:     sinceID > 0 && m.ID > uint64(sinceID),
            IsMine:    m.UserID == userID,
        }
    }

    // Reverse to show oldest first (newest at bottom for display)
    for i, j := 0, len(items)-1; i < j; i, j = i+1, j-1 {
        items[i], items[j] = items[j], items[i]
    }

    // Get other user info and referenced item
    // ... (additional queries)

    return c.JSON(ChatResponse{
        Items:         items,
        ChatID:        chatID,
        CanReply:      true,
        ItemAvailable: true, // Check if referenced item is still available
    })
}

// PostChatReply handles inline replies from AMP email
func PostChatReply(c *fiber.Ctx) error {
    userID, chatID, err := ValidateAMPToken(c)
    if err != nil {
        return c.Status(400).JSON(fiber.Map{
            "success": false,
            "message": "Unable to send reply. Please reply on Freegle.",
        })
    }

    var body struct {
        Message string `json:"message"`
    }
    if err := c.BodyParser(&body); err != nil || body.Message == "" {
        return c.Status(400).JSON(fiber.Map{
            "success": false,
            "message": "Please enter a message.",
        })
    }

    db := database.DBConn

    // Verify user is member of chat
    var membership struct {
        UserID uint64
    }
    db.Raw(`
        SELECT userid FROM chat_roster
        WHERE chatid = ? AND userid = ?
    `, chatID, userID).Scan(&membership)

    if membership.UserID == 0 {
        return c.Status(403).JSON(fiber.Map{
            "success": false,
            "message": "You are not a member of this conversation.",
        })
    }

    // Insert the message
    result := db.Exec(`
        INSERT INTO chat_messages (chatid, userid, message, type, date)
        VALUES (?, ?, ?, 'Default', NOW())
    `, chatID, userID, body.Message)

    if result.Error != nil {
        return c.Status(500).JSON(fiber.Map{
            "success": false,
            "message": "Failed to send message. Please try on Freegle.",
        })
    }

    // Update chat roster
    db.Exec(`
        UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?
    `, chatID)

    return c.JSON(fiber.Map{
        "success": true,
        "message": "Message sent!",
    })
}
```

#### 1.3 Go API - Jobs Endpoint

**File**: `iznik-server-go/amp/jobs.go`

```go
package amp

import (
    "github.com/freegle/iznik-server-go/database"
    "github.com/gofiber/fiber/v2"
)

type Job struct {
    ID       uint64 `json:"id"`
    Title    string `json:"title"`
    Location string `json:"location"`
    Image    string `json:"image"`
    URL      string `json:"url"`
}

type JobsResponse struct {
    Items []Job `json:"items"`
}

// GetJobs returns fresh job listings for AMP email
func GetJobs(c *fiber.Ctx) error {
    userID, _, err := ValidateAMPToken(c)
    if err != nil {
        // Return empty jobs - graceful degradation
        return c.JSON(JobsResponse{Items: []Job{}})
    }

    db := database.DBConn

    // Get user's last known location
    var location struct {
        Lat float64
        Lng float64
    }
    db.Raw(`
        SELECT lat, lng FROM users_locations
        WHERE userid = ?
        ORDER BY id DESC LIMIT 1
    `, userID).Scan(&location)

    if location.Lat == 0 && location.Lng == 0 {
        return c.JSON(JobsResponse{Items: []Job{}})
    }

    // Fetch nearby jobs
    var jobs []Job
    db.Raw(`
        SELECT
            j.id,
            j.title,
            j.location,
            COALESCE(ai.url, '/job-placeholder.png') as image,
            CONCAT('https://www.ilovefreegle.org/job/', j.id) as url
        FROM jobs j
        LEFT JOIN ai_images ai ON ai.title = j.title AND ai.type = 'job'
        WHERE j.visible = 1
          AND j.cpc >= 0.02
          AND ST_Distance_Sphere(
              POINT(j.lng, j.lat),
              POINT(?, ?)
          ) < 40000
        ORDER BY j.cpc DESC
        LIMIT 4
    `, location.Lng, location.Lat).Scan(&jobs)

    return c.JSON(JobsResponse{Items: jobs})
}
```

#### 1.4 Go API - Route Registration

**File**: `iznik-server-go/router/routes.go` (additions)

```go
// AMP Email endpoints (public, token-authenticated)
ampGroup := rg.Group("/amp")
ampGroup.Use(amp.AMPCORSMiddleware())

// @Summary Get chat messages for AMP email
// @Tags AMP
// @Produce json
// @Param id path int true "Chat ID"
// @Param token query string true "AMP auth token"
// @Param uid query int true "User ID"
// @Param exp query int true "Token expiry"
// @Param since query int false "Message ID to mark newer as 'new'"
// @Success 200 {object} amp.ChatResponse
// @Router /amp/chat/{id} [get]
ampGroup.Get("/chat/:id", amp.GetChatMessages)

// @Summary Post reply from AMP email
// @Tags AMP
// @Accept json
// @Produce json
// @Param id path int true "Chat ID"
// @Param token query string true "AMP auth token"
// @Param body body object true "Message body"
// @Success 200 {object} object
// @Router /amp/chat/{id}/reply [post]
ampGroup.Post("/chat/:id/reply", amp.PostChatReply)

// @Summary Get jobs for AMP email
// @Tags AMP
// @Produce json
// @Param token query string true "AMP auth token"
// @Success 200 {object} amp.JobsResponse
// @Router /amp/jobs [get]
ampGroup.Get("/jobs", amp.GetJobs)

// @Summary Track AMP email open
// @Tags AMP
// @Param id path string true "Tracking ID"
// @Success 200
// @Router /amp/track/{id} [get]
ampGroup.Get("/track/:id", amp.TrackOpen)
```

### Phase 2: Laravel Email Infrastructure

#### 2.1 AmpEmail Trait

**File**: `iznik-batch/app/Mail/Traits/AmpEmail.php`

```php
<?php

namespace App\Mail\Traits;

use App\Models\AmpWriteToken;
use Illuminate\Support\Str;

/**
 * Trait to add AMP email capabilities to MJML mailables.
 *
 * Security Model:
 * - READ tokens: HMAC-based, reusable within expiry period
 * - WRITE tokens: Database-stored nonce, ONE-TIME USE only
 *
 * This protects against email forwarding attacks:
 * - If forwarded, recipient can view messages (privacy leak, acceptable)
 * - If forwarded, recipient can only send ONE reply (then token is invalidated)
 *
 * Usage:
 * 1. Add `use AmpEmail;` to your mailable class
 * 2. Call `$this->initAmp(...)` in the mailable constructor
 * 3. Override `getAmpTemplate()` to return your AMP template path
 * 4. The trait handles MIME structure and token generation automatically
 */
trait AmpEmail
{
    protected bool $ampEnabled = false;
    protected array $ampData = [];
    protected ?string $ampReadToken = null;
    protected ?string $ampWriteNonce = null;

    /**
     * Initialize AMP email support with separate read/write tokens.
     *
     * @param int $userId User ID for token generation
     * @param int $resourceId Resource ID (chat ID, etc.)
     * @param int|null $emailTrackingId Link to email tracking record
     * @param int $expiryDays Token validity in days (default 31)
     */
    protected function initAmp(
        int $userId,
        int $resourceId,
        ?int $emailTrackingId = null,
        int $expiryDays = 31
    ): void {
        $this->ampEnabled = config('freegle.amp.enabled', false);

        if (!$this->ampEnabled) {
            return;
        }

        $expiry = time() + ($expiryDays * 86400);

        // Generate READ token (HMAC-based, reusable)
        $this->ampReadToken = $this->generateReadToken($userId, $resourceId, $expiry);

        // Generate WRITE token (database nonce, one-time use)
        $this->ampWriteNonce = $this->generateWriteToken(
            $userId,
            $resourceId,
            $emailTrackingId,
            $expiryDays
        );

        $this->ampData = [
            'ampReadToken' => $this->ampReadToken,
            'ampWriteNonce' => $this->ampWriteNonce,
            'ampUserId' => $userId,
            'ampResourceId' => $resourceId,
            'ampExpiry' => $expiry,
            'ampApiBase' => config('freegle.amp.api_base'),
        ];
    }

    /**
     * Generate HMAC-based read token (reusable within expiry).
     */
    protected function generateReadToken(int $userId, int $resourceId, int $expiry): string
    {
        $message = "read" . $userId . $resourceId . $expiry;
        $secret = config('freegle.amp.secret');

        return hash_hmac('sha256', $message, $secret);
    }

    /**
     * Generate one-time-use write token (stored in database).
     */
    protected function generateWriteToken(
        int $userId,
        int $resourceId,
        ?int $emailTrackingId,
        int $expiryDays
    ): string {
        // Generate cryptographically secure random nonce
        $nonce = bin2hex(random_bytes(32));

        // Store in database for later validation
        AmpWriteToken::create([
            'nonce' => $nonce,
            'user_id' => $userId,
            'chat_id' => $resourceId,
            'email_tracking_id' => $emailTrackingId,
            'expires_at' => now()->addDays($expiryDays),
        ]);

        return $nonce;
    }

    /**
     * Get the AMP template path. Override in mailable.
     */
    protected function getAmpTemplate(): ?string
    {
        return null;
    }

    /**
     * Build AMP read URL (for amp-list).
     */
    protected function ampReadUrl(string $path): string
    {
        $base = config('freegle.amp.api_base');
        $params = http_build_query([
            'rt' => $this->ampReadToken,  // Read token
            'uid' => $this->ampData['ampUserId'],
            'exp' => $this->ampData['ampExpiry'],
        ]);

        return "{$base}{$path}?{$params}";
    }

    /**
     * Build AMP write URL (for amp-form).
     */
    protected function ampWriteUrl(string $path): string
    {
        $base = config('freegle.amp.api_base');
        $params = http_build_query([
            'wt' => $this->ampWriteNonce,  // Write token (one-time nonce)
        ]);

        return "{$base}{$path}?{$params}";
    }

    /**
     * Check if AMP is enabled for this email.
     */
    public function isAmpEnabled(): bool
    {
        return $this->ampEnabled && $this->getAmpTemplate() !== null;
    }

    /**
     * Get the rendered AMP content.
     */
    public function getAmpContent(): string
    {
        if (!$this->isAmpEnabled()) {
            return '';
        }

        $template = $this->getAmpTemplate();
        if (!$template || !view()->exists($template)) {
            return '';
        }

        return view($template, array_merge(
            $this->getDefaultData(),
            $this->mjmlData ?? [],
            $this->ampData,
            $this->getAmpData()
        ))->render();
    }

    /**
     * Get additional AMP-specific data. Override in mailable.
     */
    protected function getAmpData(): array
    {
        return [];
    }
}
```

**File**: `iznik-batch/app/Models/AmpWriteToken.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One-time-use write tokens for AMP email forms.
 *
 * Security: These tokens can only be used ONCE.
 * After successful use, the used_at field is set and the token is invalidated.
 */
class AmpWriteToken extends Model
{
    protected $table = 'amp_write_tokens';

    public $timestamps = false;

    protected $fillable = [
        'nonce',
        'user_id',
        'chat_id',
        'email_tracking_id',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Scope: only valid (unused and not expired) tokens.
     */
    public function scopeValid($query)
    {
        return $query->whereNull('used_at')
                     ->where('expires_at', '>', now());
    }

    /**
     * Check if this token can still be used.
     */
    public function isValid(): bool
    {
        return $this->used_at === null && $this->expires_at > now();
    }

    /**
     * Mark token as used. Returns false if already used (race condition).
     */
    public function markAsUsed(): bool
    {
        if ($this->used_at !== null) {
            return false;
        }

        $affected = static::where('id', $this->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        return $affected > 0;
    }
}
```

#### 2.2 Updated MjmlMailable Base Class

**File**: `iznik-batch/app/Mail/MjmlMailable.php` (modifications)

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Mjml\Mjml;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\TextPart;

abstract class MjmlMailable extends Mailable
{
    use Queueable, SerializesModels;

    protected string $mjmlTemplate;
    protected array $mjmlData = [];
    protected ?string $textTemplate = null;
    protected ?string $textContent = null;

    /**
     * Build the message with optional AMP support.
     */
    public function build(): static
    {
        // Render MJML to HTML
        $mjmlContent = view($this->mjmlTemplate, $this->mjmlData)->render();
        $html = $this->compileMjml($mjmlContent);

        // Render plain text if template exists
        if ($this->textTemplate && view()->exists($this->textTemplate)) {
            $this->textContent = view($this->textTemplate, $this->mjmlData)->render();
        }

        // Check if this mailable has AMP support
        $hasAmp = method_exists($this, 'isAmpEnabled') && $this->isAmpEnabled();

        if ($hasAmp) {
            // Build multipart message with AMP
            $this->buildMultipartWithAmp($html);
        } else {
            // Standard HTML email
            $this->html($html);
            if ($this->textContent) {
                $this->text($this->textTemplate, $this->mjmlData);
            }
        }

        return $this;
    }

    /**
     * Build multipart/alternative message with AMP part.
     *
     * Order matters: text/plain, text/x-amp-html, text/html
     * AMP part must come before HTML for proper client detection.
     */
    protected function buildMultipartWithAmp(string $html): void
    {
        $ampContent = $this->getAmpContent();

        $this->withSymfonyMessage(function (Email $message) use ($html, $ampContent) {
            $parts = [];

            // 1. Plain text (first, lowest priority)
            if ($this->textContent) {
                $parts[] = new TextPart($this->textContent, 'utf-8', 'plain');
            }

            // 2. AMP HTML (before regular HTML)
            if ($ampContent) {
                $parts[] = new TextPart($ampContent, 'utf-8', 'x-amp-html');
            }

            // 3. Regular HTML (last, highest priority for non-AMP clients)
            $parts[] = new TextPart($html, 'utf-8', 'html');

            // Build multipart/alternative
            $alternative = new AlternativePart(...$parts);
            $message->setBody($alternative);
        });
    }

    // ... rest of existing methods unchanged ...
}
```

#### 2.3 Configuration

**File**: `iznik-batch/config/freegle.php` (additions)

```php
'amp' => [
    'enabled' => env('FREEGLE_AMP_ENABLED', false),
    'secret' => env('FREEGLE_AMP_SECRET'),
    'api_base' => env('FREEGLE_AMP_API_BASE', 'https://apiv2.ilovefreegle.org'),
    'token_expiry_days' => env('FREEGLE_AMP_TOKEN_EXPIRY', 31),

    // Test sender for development (add to Gmail Developer Settings)
    'test_sender' => env('FREEGLE_AMP_TEST_SENDER', 'amp-test@users.ilovefreegle.org'),

    // Email types that support AMP
    'enabled_types' => [
        'ChatNotification',
        'Digest',
    ],
],
```

### Phase 3: Chat Notification AMP Template

#### 3.1 AMP Template

**File**: `iznik-batch/resources/views/emails/amp/chat/notification.blade.php`

```html
<!doctype html>
<html ⚡4email data-css-strict>
<head>
    <meta charset="utf-8">
    <style amp4email-boilerplate>body{visibility:hidden}</style>
    <script async src="https://cdn.ampproject.org/v0.js"></script>
    <script async custom-element="amp-list" src="https://cdn.ampproject.org/v0/amp-list-0.1.js"></script>
    <script async custom-element="amp-form" src="https://cdn.ampproject.org/v0/amp-form-0.1.js"></script>
    <script async custom-template="amp-mustache" src="https://cdn.ampproject.org/v0/amp-mustache-0.2.js"></script>

    <style amp-custom>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
        }
        .header {
            background: #5cb85c;
            color: white;
            padding: 20px;
            text-align: center;
        }
        .content {
            padding: 20px;
        }
        .message {
            padding: 12px 16px;
            margin: 8px 0;
            border-radius: 12px;
            max-width: 80%;
        }
        .message-theirs {
            background: #e9ecef;
            margin-right: auto;
        }
        .message-mine {
            background: #5cb85c;
            color: white;
            margin-left: auto;
        }
        .message-new {
            border-left: 3px solid #ff9800;
            font-weight: 500;
        }
        .message-meta {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
        .message-mine .message-meta {
            color: rgba(255,255,255,0.8);
        }
        .new-badge {
            display: inline-block;
            background: #ff9800;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 8px;
        }
        .reply-form {
            padding: 20px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
        }
        .reply-form textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            resize: vertical;
            min-height: 80px;
            font-family: inherit;
            font-size: 14px;
            box-sizing: border-box;
        }
        .reply-form button {
            background: #5cb85c;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            margin-top: 10px;
        }
        .reply-form button:hover {
            background: #4cae4c;
        }
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 8px;
            margin-top: 10px;
        }
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 8px;
            margin-top: 10px;
        }
        .fallback {
            padding: 20px;
            text-align: center;
            color: #666;
        }
        .fallback a {
            color: #5cb85c;
        }
        .placeholder {
            text-align: center;
            padding: 20px;
            color: #999;
        }
        .view-link {
            display: block;
            text-align: center;
            padding: 15px;
            color: #5cb85c;
            text-decoration: none;
        }
        .static-content {
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
        }
        .static-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin: 0;">{{ $senderName }} sent you a message</h2>
            @if($refMessageSubject)
                <p style="margin: 10px 0 0; opacity: 0.9;">Regarding: {{ $refMessageSubject }}</p>
            @endif
        </div>

        {{-- Static content shown immediately (the original notification) --}}
        <div class="static-content">
            <div class="static-label">Message that triggered this notification:</div>
            <div class="message message-theirs">
                <div>{{ $messageText }}</div>
                <div class="message-meta">{{ $messageDate }}</div>
            </div>
        </div>

        <div class="content">
            {{-- Dynamic content: fetch latest messages --}}
            <amp-list
                src="{{ $ampChatUrl }}"
                items="items"
                layout="fixed-height"
                height="400"
                binding="refresh"
            >
                <template type="amp-mustache">
                    <div class="message @{{#isMine}}message-mine@{{/isMine}}@{{^isMine}}message-theirs@{{/isMine}} @{{#isNew}}message-new@{{/isNew}}">
                        <div>
                            @{{message}}
                            @{{#isNew}}<span class="new-badge">NEW</span>@{{/isNew}}
                        </div>
                        <div class="message-meta">
                            @{{^isMine}}@{{fromUser}} &middot; @{{/isMine}}
                            @{{date}}
                        </div>
                    </div>
                </template>

                <div placeholder class="placeholder">
                    Loading latest messages...
                </div>

                <div fallback class="fallback">
                    <p>Couldn't load latest messages.</p>
                    <a href="{{ $chatUrl }}">View conversation on Freegle</a>
                </div>
            </amp-list>
        </div>

        {{-- Inline reply form --}}
        <div class="reply-form">
            <amp-form
                action-xhr="{{ $ampReplyUrl }}"
                method="post"
            >
                <textarea
                    name="message"
                    placeholder="Type your reply..."
                    required
                    minlength="1"
                    maxlength="10000"
                ></textarea>
                <button type="submit">Send Reply</button>

                <div submit-success class="success-message">
                    <template type="amp-mustache">
                        ✓ @{{message}}
                    </template>
                </div>

                <div submit-error class="error-message">
                    <template type="amp-mustache">
                        @{{message}} <a href="{{ $chatUrl }}">Reply on Freegle</a>
                    </template>
                </div>
            </amp-form>
        </div>

        <a href="{{ $chatUrl }}" class="view-link">
            View full conversation on Freegle →
        </a>
    </div>
</body>
</html>
```

#### 3.2 Updated ChatNotification Mailable

**File**: `iznik-batch/app/Mail/Chat/ChatNotification.php` (modifications)

```php
<?php

namespace App\Mail\Chat;

use App\Mail\MjmlMailable;
use App\Mail\Traits\AmpEmail;
use App\Mail\Traits\TrackableEmail;
// ... other imports

class ChatNotification extends MjmlMailable
{
    use TrackableEmail, AmpEmail;

    // ... existing properties ...

    public function __construct(/* existing params */)
    {
        // ... existing constructor code ...

        // Initialize AMP support
        $this->initAmp(
            $this->recipient->id,
            $this->chatRoom->id,
            31 // 31 days expiry
        );
    }

    /**
     * Get the AMP template path.
     */
    protected function getAmpTemplate(): ?string
    {
        // Only enable AMP for user-to-user chats initially
        if ($this->chatType !== ChatRoom::TYPE_USER2USER) {
            return null;
        }

        return 'emails.amp.chat.notification';
    }

    /**
     * Get AMP-specific template data.
     */
    protected function getAmpData(): array
    {
        $chatId = $this->chatRoom->id;
        $sinceId = $this->message->id; // Mark messages after this as "new"

        return [
            'ampChatUrl' => $this->ampUrl("/amp/chat/{$chatId}") . "&since={$sinceId}",
            'ampReplyUrl' => $this->ampUrl("/amp/chat/{$chatId}/reply"),
            'chatUrl' => config('freegle.sites.user') . "/chats/{$chatId}",
            'messageText' => $this->message->message,
            'messageDate' => $this->message->date->format('j M Y, g:ia'),
        ];
    }

    // ... rest of existing code ...
}
```

### Phase 4: Jobs Section AMP Support

#### 4.1 Jobs AMP Component Template

**File**: `iznik-batch/resources/views/emails/amp/components/jobs.blade.php`

```html
{{-- Include in AMP templates that want dynamic jobs --}}
<amp-list
    src="{{ $ampJobsUrl }}"
    items="items"
    layout="fixed-height"
    height="300"
    binding="no"
>
    <template type="amp-mustache">
        <div style="display: flex; align-items: center; padding: 10px; border-bottom: 1px solid #eee;">
            <amp-img
                src="@{{image}}"
                width="60"
                height="60"
                layout="fixed"
                style="border-radius: 4px; margin-right: 12px;"
            ></amp-img>
            <div style="flex: 1;">
                <a href="@{{url}}" style="color: #333; text-decoration: none; font-weight: 500;">
                    @{{title}}
                </a>
                <div style="font-size: 12px; color: #666;">@{{location}}</div>
            </div>
        </div>
    </template>

    <div placeholder style="text-align: center; padding: 20px; color: #999;">
        Loading job opportunities...
    </div>

    <div fallback>
        {{-- Empty fallback - just don't show jobs if they fail to load --}}
    </div>
</amp-list>

<p style="font-size: 11px; color: #999; padding: 10px;">
    Clicking on jobs helps support Freegle.
</p>
```

### Phase 5: UX Best Practices Implementation

#### 5.1 Avoiding User Confusion

Based on research from [Mailmodo](https://www.mailmodo.com/guides/amp-for-email/) and [amp.dev](https://amp.dev/documentation/guides-and-tutorials/develop/amp_email_best_practices):

1. **Use placeholders that match final layout**
   - Prevent jarring layout shifts when content loads
   - Show skeleton/loading states that match expected content size

2. **Limit interactive components**
   - Use 1-2 AMP components per email maximum
   - Don't overwhelm users with too much interactivity

3. **Always show static context first**
   - The message that triggered the notification is shown immediately (not via AMP)
   - Dynamic content augments, not replaces, the static content

4. **Clear visual indicators**
   - "NEW" badges on messages that arrived after the email was sent
   - Loading states are obvious ("Loading latest messages...")
   - Fallbacks provide clear action ("View on Freegle")

5. **Graceful degradation**
   - Non-AMP clients see complete, functional HTML email
   - API failures result in helpful fallback messages, not broken layouts
   - Static content always visible regardless of AMP support

6. **Inline reply expectations**
   - Success/error feedback is immediate and clear
   - Error states include fallback action (link to web)
   - Form is clearly distinguished from message list

---

## Phase 6: Google Sender Registration

### Prerequisites (Complete Before Registration)

1. **SPF/DKIM/DMARC Setup** (must be in place for 3+ months)
   - SPF record for ilovefreegle.org
   - DKIM signing enabled
   - DMARC policy published

2. **Sending History**
   - Must have "an order of hundreds" of emails from the domain
   - Consistent sending for several weeks

3. **Production-Ready Email**
   - Complete AMP implementation
   - Tested thoroughly in Gmail (Developer Mode)

### Registration Process

Based on [Google's registration guide](https://developers.google.com/gmail/ampemail/register) and [amp.dev sender distribution](https://amp.dev/documentation/guides-and-tutorials/start/email_sender_distribution):

1. **Test in Gmail Developer Mode**
   - Enable via Gmail Settings > General > Dynamic email
   - Verify AMP renders correctly

2. **Send Production Email**
   - Send real email (not test) to: `ampforemail.whitelisting@gmail.com`
   - Must come from production servers with correct SPF/DKIM
   - Cannot be forwarded (Gmail strips AMP on forward)

3. **Submit Registration Form**
   - Fill out: [AMP for Email: Sender Registration](https://docs.google.com/forms/d/e/1FAIpQLSdso95e7UDLk_R-bnpzsAmuUMDQEMUgTErcfGGItBDkghHU2A/viewform)
   - Single form covers Gmail, Yahoo, Mail.ru

4. **Await Approval**
   - Google typically responds within 5 working days
   - May take up to 2 weeks to take effect after approval

5. **Monitor Compliance**
   - Must continue meeting Gmail Bulk Sender Guidelines
   - Registration can be revoked for policy violations

### Contact for Issues

- Email: `ampforemail+registration@google.com`
- GitHub: [AMP for Email Working Group](https://github.com/nicknordlikerock/wg-amp4email)

---

## Testing Before Whitelisting

You can test AMP emails in Gmail **before** being officially whitelisted with Google.

### Freegle Test Configuration

We use a dedicated test sender address for AMP development:

**Test sender**: `amp-test@users.ilovefreegle.org`

This address will be:
1. Configured in Gmail Developer Settings for testers
2. Used by the `mail:test` artisan command when testing AMP emails
3. Separate from production sender addresses

### Gmail Developer Mode

1. Go to **Gmail Settings** (gear icon) > **See all settings**
2. Navigate to **General** tab
3. Find **Dynamic email** section
4. Click **Developer settings**
5. Add: `amp-test@users.ilovefreegle.org`
6. Save changes

Now emails from that address will render AMP content in your Gmail account.

### Laravel Test Command

Update the test command to use the AMP test sender:

**File**: `iznik-batch/app/Console/Commands/Mail/TestEmailCommand.php`

```php
// When testing AMP emails, use the dedicated test sender
protected function getFromAddress(): string
{
    if ($this->option('amp')) {
        return config('freegle.amp.test_sender', 'amp-test@users.ilovefreegle.org');
    }

    return config('freegle.mail.noreply_addr');
}
```

Usage:
```bash
# Send test chat notification with AMP
docker exec freegle-batch php artisan mail:test chat --amp --to=your-gmail@gmail.com

# Send test digest with AMP
docker exec freegle-batch php artisan mail:test digest --amp --to=your-gmail@gmail.com
```

### AMP for Email Playground

Google provides a playground for testing:

1. Go to [Gmail AMP Playground](https://developers.google.com/gmail/ampemail/playground)
2. In Gmail Developer settings, whitelist `amp@gmail.dev`
3. Paste your AMP HTML into the playground
4. Click "Send" to receive a test email

### Debugging Banners

With Developer Mode enabled, Gmail shows debugging information:
- Why AMP didn't render (validation errors)
- Which component failed
- CORS issues

### Local API Testing

For testing the API endpoints before deploying:

```bash
# Test read endpoint
curl -H "AMP-Email-Sender: test@ilovefreegle.org" \
  "http://localhost:8193/api/amp/chat/123?rt=test_token&uid=456&exp=9999999999"

# Test write endpoint (with valid nonce from DB)
curl -X POST \
  -H "AMP-Email-Sender: test@ilovefreegle.org" \
  -H "Content-Type: application/json" \
  -d '{"message":"Test reply"}' \
  "http://localhost:8193/api/amp/chat/123/reply?wt=test_nonce"
```

### Checklist Before Registration

- [ ] AMP validates in [AMP Validator](https://validator.ampproject.org/)
- [ ] Email renders in Gmail Developer Mode
- [ ] Read endpoint returns messages correctly
- [ ] Write endpoint accepts and stores replies
- [ ] CORS headers are correct (check browser devtools)
- [ ] Fallback content displays when AMP fails
- [ ] One-time tokens are invalidated after use
- [ ] Expired tokens are rejected gracefully

---

## Testing Strategy

### Unit Tests

```php
// Test AMP token generation
public function test_amp_token_generation(): void
{
    $mailable = new ChatNotification(/* params */);
    $token = $mailable->generateAmpToken(123, 456, 31);

    $this->assertNotEmpty($token);
    $this->assertEquals(64, strlen($token)); // SHA256 hex
}

// Test AMP URL building
public function test_amp_url_includes_auth_params(): void
{
    $mailable = new ChatNotification(/* params */);
    $url = $mailable->ampUrl('/amp/chat/123');

    $this->assertStringContainsString('token=', $url);
    $this->assertStringContainsString('uid=', $url);
    $this->assertStringContainsString('exp=', $url);
}

// Test multipart MIME structure
public function test_email_has_amp_mime_part(): void
{
    $mailable = new ChatNotification(/* params */);
    $rendered = $mailable->render();

    // Check MIME structure includes x-amp-html
    $message = $mailable->build()->getSymfonyMessage();
    $body = $message->getBody();

    $this->assertInstanceOf(AlternativePart::class, $body);
}
```

### Go API Tests

```go
func TestAMPChatEndpoint(t *testing.T) {
    // Generate valid token
    token := generateTestToken(userID, chatID, time.Now().Add(24*time.Hour))

    req := httptest.NewRequest("GET",
        fmt.Sprintf("/api/amp/chat/%d?token=%s&uid=%d&exp=%d",
            chatID, token, userID, expiry),
        nil)
    req.Header.Set("AMP-Email-Sender", "test@ilovefreegle.org")

    resp, _ := app.Test(req)

    assert.Equal(t, 200, resp.StatusCode)
    assert.Equal(t, "test@ilovefreegle.org", resp.Header.Get("AMP-Email-Allow-Sender"))
}

func TestAMPReplyEndpoint(t *testing.T) {
    token := generateTestToken(userID, chatID, time.Now().Add(24*time.Hour))

    body := strings.NewReader(`{"message":"Test reply"}`)
    req := httptest.NewRequest("POST",
        fmt.Sprintf("/api/amp/chat/%d/reply?token=%s&uid=%d&exp=%d",
            chatID, token, userID, expiry),
        body)
    req.Header.Set("Content-Type", "application/json")
    req.Header.Set("AMP-Email-Sender", "test@ilovefreegle.org")

    resp, _ := app.Test(req)

    assert.Equal(t, 200, resp.StatusCode)
}

func TestAMPTokenExpiry(t *testing.T) {
    // Token with past expiry
    token := generateTestToken(userID, chatID, time.Now().Add(-1*time.Hour))

    req := httptest.NewRequest("GET",
        fmt.Sprintf("/api/amp/chat/%d?token=%s&uid=%d&exp=%d",
            chatID, token, userID, pastExpiry),
        nil)

    resp, _ := app.Test(req)

    // Should return graceful empty response, not error
    assert.Equal(t, 200, resp.StatusCode)

    var result ChatResponse
    json.NewDecoder(resp.Body).Decode(&result)
    assert.Empty(t, result.Items)
    assert.False(t, result.CanReply)
}
```

### Manual Testing Checklist

- [ ] AMP renders in Gmail (Developer Mode enabled)
- [ ] Dynamic messages load correctly
- [ ] New messages show "NEW" badge
- [ ] Inline reply submits successfully
- [ ] Reply confirmation shows in email
- [ ] Expired token shows graceful fallback
- [ ] Deleted user shows graceful fallback
- [ ] Non-AMP clients see full HTML email
- [ ] Plain text fallback is readable
- [ ] Jobs section loads fresh listings
- [ ] All tracked links still work
- [ ] Tracking pixel still fires

---

## Rollout Plan

### Phase 1: Development & Testing ✅ COMPLETE
- ✅ Implement Go API endpoints
- ✅ Implement Laravel AMP traits and templates
- ✅ Unit and integration tests
- ✅ Internal testing with Gmail Developer Mode
- ✅ Loki logging for reply source analytics

### Phase 2: Google/Yahoo Registration ⏳ NOT STARTED
- Submit registration to Google (single form covers Gmail, Yahoo, Mail.ru)
- Ensure SPF/DKIM/DMARC requirements are met
- Await approval (typically 5 working days)
- Test with real Gmail delivery post-approval

### Phase 3: Limited Rollout
- Enable for small percentage of users (feature flag)
- Monitor for errors and issues
- Gather feedback on UX
- Review Loki analytics for reply source distribution

### Phase 4: Full Rollout
- Enable for all eligible emails
- Monitor metrics (open rates, reply rates, engagement)
- Iterate based on data

---

## Configuration Reference

### Environment Variables

```bash
# Enable AMP email support
FREEGLE_AMP_ENABLED=true

# Secret for HMAC token generation (generate with: openssl rand -hex 32)
FREEGLE_AMP_SECRET=your-secret-key-here

# API base URL for AMP requests
FREEGLE_AMP_API_BASE=https://apiv2.ilovefreegle.org

# Token validity in days
FREEGLE_AMP_TOKEN_EXPIRY=31
```

### Database Changes

**New table required for one-time write tokens:**

```sql
CREATE TABLE amp_write_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nonce VARCHAR(64) NOT NULL UNIQUE,           -- Random hex string
    user_id BIGINT UNSIGNED NOT NULL,
    chat_id BIGINT UNSIGNED NOT NULL,
    email_tracking_id BIGINT UNSIGNED,           -- Links to email_tracking table
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,                       -- NULL until used
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_nonce (nonce),
    INDEX idx_expires (expires_at),
    INDEX idx_user_chat (user_id, chat_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (chat_id) REFERENCES chat_rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cleanup job: delete expired tokens older than 7 days
-- Run daily via cron
DELETE FROM amp_write_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
```

**Existing tables used:
- `chat_messages` - Message data
- `chat_roster` - Chat membership
- `chat_rooms` - Chat metadata
- `users` - User data
- `jobs` - Job listings
- `email_tracking` - Tracking (existing)

---

## Security Considerations

1. **Token Security**
   - HMAC-SHA256 prevents token forgery
   - Tokens expire after 31 days
   - Tokens are scoped to specific user + resource

2. **CORS Protection**
   - Only allow requests from Freegle sending domains
   - Validate sender email in both v1 and v2 flows

3. **Rate Limiting**
   - Apply standard API rate limits to AMP endpoints
   - Consider stricter limits for reply endpoint

4. **Input Validation**
   - Validate message length (max 10000 chars)
   - Sanitise user input before storage
   - Verify chat membership before allowing operations

5. **Graceful Failures**
   - Never expose internal errors to client
   - Return safe fallback data on any failure
   - Log errors server-side for debugging

---

## Sources

- [CORS in AMP for Email](https://amp.dev/documentation/guides-and-tutorials/learn/cors-in-email) - amp.dev
- [AMP Email Security Requirements](https://developers.google.com/gmail/ampemail/security-requirements) - Google Developers
- [Register for Sender Distribution](https://amp.dev/documentation/guides-and-tutorials/start/email_sender_distribution) - amp.dev
- [AMP Email Best Practices](https://amp.dev/documentation/guides-and-tutorials/develop/amp_email_best_practices) - amp.dev
- [What is AMP Email](https://www.mailmodo.com/guides/amp-for-email/) - Mailmodo
- [How to Get Whitelisted](https://stripo.email/blog/how-to-get-whitelisted-with-google-to-send-amp-emails-our-personal-experience/) - Stripo
- [laravel-amp-email-mailable](https://github.com/judge2020/laravel-amp-email-mailable) - GitHub
- [AMP for Email Fundamentals](https://amp.dev/documentation/guides-and-tutorials/learn/email_fundamentals) - amp.dev
