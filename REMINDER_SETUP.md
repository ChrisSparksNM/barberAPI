# Automated SMS Reminder Setup

This system automatically sends SMS reminders to customers 24 hours before their appointments using Twilio.

## Features

- **Automated Reminders**: Sends SMS 24 hours before appointments
- **Smart Filtering**: Only sends to customers with phone numbers and SMS enabled
- **Duplicate Prevention**: Tracks when reminders are sent to avoid duplicates
- **Manual Trigger**: Admins can manually send all reminders from dashboard
- **Dry Run Mode**: Test the system without actually sending SMS

## Setup Instructions

### 1. Configure Twilio

1. Sign up for a Twilio account at [twilio.com](https://twilio.com)
2. Get your Account SID, Auth Token, and phone number
3. Update your `.env` file:

```env
TWILIO_SID=your_actual_account_sid
TWILIO_AUTH_TOKEN=your_actual_auth_token
TWILIO_FROM_NUMBER=+1234567890
```

### 2. Set Up Scheduling

The system is configured to run automatically every hour. To enable this:

#### Option A: Using Cron (Production)
Add this to your server's crontab:
```bash
* * * * * cd /path/to/your/project && php artisan schedule:run >> /dev/null 2>&1
```

#### Option B: Manual Testing
Run the command manually:
```bash
# Test without sending (dry run)
php artisan appointments:send-reminders --dry-run

# Actually send reminders
php artisan appointments:send-reminders
```

### 3. Dashboard Manual Trigger

Admins can manually trigger reminders from the web dashboard:
1. Login as admin (`admin@taosempire.com`)
2. Click "📱 Send All Reminders" button
3. Confirm to send reminders to all eligible customers

## How It Works

### Eligibility Criteria
Reminders are sent to appointments that meet ALL these conditions:
- Appointment is scheduled for tomorrow (within 24 hours)
- Appointment status is 'scheduled' (not cancelled/completed/no-show)
- Customer has a phone number on file
- Customer has SMS notifications enabled
- Reminder hasn't been sent in the last 23 hours

### SMS Message Format
```
🪒 Appointment Reminder

Hi [Customer Name]!

This is a friendly reminder that your appointment with [Barber] is scheduled for TOMORROW:

📅 [Date]
🕐 [Time]
✂️ Services: [Service List]
💰 Total: $[Amount]

📍 Taos Empire Barber Shop
We look forward to seeing you!

Reply STOP to opt out of reminders.
```

## Testing

### Create Test Appointments
```bash
php artisan db:seed --class=TomorrowAppointmentsSeeder
```

### Test Reminder System
```bash
# See what would be sent
php artisan appointments:send-reminders --dry-run

# Actually send (make sure Twilio is configured)
php artisan appointments:send-reminders
```

## Monitoring

- Check Laravel logs for SMS sending results
- Twilio dashboard shows delivery status
- `reminder_sent_at` field tracks when reminders were sent

## Troubleshooting

### No Reminders Sent
- Check Twilio credentials in `.env`
- Verify customers have phone numbers
- Ensure appointments are for tomorrow
- Check `reminder_sent_at` field isn't blocking duplicates

### SMS Not Delivered
- Check Twilio logs for delivery status
- Verify phone number format
- Check customer's SMS preferences

### Scheduling Not Working
- Verify cron job is set up correctly
- Check Laravel scheduler is running
- Test manual command execution