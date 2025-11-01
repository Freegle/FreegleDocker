# Freegle Yesterday - Two-Factor Authentication Setup Guide

Welcome! You've been granted access to the Freegle Yesterday backup management system. This system uses two-factor authentication (2FA) to keep our historical data secure.

## What You Need

A smartphone with one of these free authenticator apps:
- **Google Authenticator** (iOS/Android) - Most popular
- **Microsoft Authenticator** (iOS/Android)
- **Authy** (iOS/Android/Desktop)
- Any other TOTP-compatible authenticator app

## Setting Up Your Account

You should have received:
1. Your **username**
2. A **QR code image** or **secret key**

### Option 1: Scan the QR Code (Easiest)

1. **Open your authenticator app** on your phone

2. **Tap "Add Account"** or the **"+"** button

3. **Choose "Scan QR Code"**

4. **Point your camera at the QR code** in your email/message

5. **Done!** Your app now shows a 6-digit code that changes every 30 seconds

### Option 2: Enter the Secret Key Manually

If you can't scan the QR code:

1. **Open your authenticator app** on your phone

2. **Tap "Add Account"** or the **"+"** button

3. **Choose "Enter a setup key"** or **"Manual entry"**

4. **Enter these details:**
   - **Account name:** `Freegle Yesterday`
   - **Your username:** `[provided by admin]`
   - **Secret key:** `[32-character code provided by admin]`
   - **Type:** Time-based (TOTP)

5. **Done!** Your app now shows a 6-digit code that changes every 30 seconds

## Logging In

1. **Go to:** https://yesterday.ilovefreegle.org

2. **Enter your username**

3. **Open your authenticator app** and look at the 6-digit code for "Freegle Yesterday"

4. **Enter the 6-digit code** (you have about 30 seconds before it changes)

5. **Click "Authenticate"**

**Success!** Your IP address is now whitelisted for 1 hour. You won't need to enter the code again for 1 hour (unless you change location/IP).

## Important Notes

### Your Code Changes Every 30 Seconds
- If you see the code about to change (timer almost up), wait for the new code
- Each code only works once

### IP Whitelisting
- After successful login, your IP address is remembered for 1 hour
- You can use the system freely during this time without entering codes
- After 1 hour, you'll need to authenticate again

### Keep Your Phone Safe
- Your authenticator app is your key to access
- Back up your authenticator app if possible (Authy and Microsoft Authenticator support cloud backup)
- If you lose your phone, contact an administrator immediately to reset your access

### Multiple Devices
You can add the same account to multiple devices:
- Scan the same QR code on another phone
- Or enter the same secret key on another device
- All devices will show the same codes

## Troubleshooting

### "Invalid token" error
- Make sure you're entering the current code (not an expired one)
- Check your phone's time is correct (Settings → Date & Time → Set Automatically)
- Try the next code if you're near the 30-second boundary

### Lost your phone or authenticator app?
Contact an administrator to reset your account. They will need to:
1. Remove your old account
2. Create a new account with a new QR code/secret
3. Send you new setup details

### QR code doesn't scan
- Make sure your camera can focus clearly on the code
- Try making the QR code larger on your screen
- Use Option 2 (manual entry) instead

### Can't receive the setup information
Check your spam/junk folder, or contact the administrator who set up your account.

## Security Tips

✅ **DO:**
- Keep your phone secure with a PIN/password/biometric lock
- Log out if using a shared computer
- Contact admins immediately if you suspect unauthorized access

❌ **DON'T:**
- Share your username or authenticator codes with anyone
- Screenshot your authenticator codes and send them via messaging apps
- Use the same simple password everywhere

## Getting Help

If you have any issues setting up or using 2FA authentication, contact the Freegle tech team at geeks@ilovefreegle.org.

## What is Yesterday?

The Yesterday system provides access to historical Freegle production backups for:
- Data recovery
- Historical data analysis
- Testing and debugging with real data
- Investigating past issues

All data is read-only and isolated from production. OAuth logins don't work - use email/password authentication to access the restored systems.

---

**System URL:** https://yesterday.ilovefreegle.org

**Support:** geeks@ilovefreegle.org
