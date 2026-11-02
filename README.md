# Talsinet Integration Hub

## Local database connection

The application connects to the existing MariaDB database `talsinet_hub` using
PDO and the MySQL PDO driver. By default, it uses `127.0.0.1:3306` and the local
`root` account with an empty password, as in a default XAMPP installation.

To use different connection settings, set these environment variables in the
PowerShell window where you start the PHP server:

```powershell
$env:DB_HOST = "127.0.0.1"
$env:DB_PORT = "3306"
$env:DB_DATABASE = "talsinet_hub"
$env:DB_USERNAME = "root"
$env:DB_PASSWORD = "your MariaDB password"
php -S localhost:8000 -t public public/index.php
```

If the server is already running, stop it with `Ctrl+C`, set the variables, and
start it again. The `/` endpoint reports whether the database connection
succeeded. The `public/.htaccess` rewrite enables the same routes under Apache.
Connection failures are logged to the PHP server console; health responses do
not expose credentials or internal database errors.

## WhatsApp gateway database

The WhatsApp gateway uses the existing `talsinet_hub` database and
`applications` table; it does not create another project or modify the payment
integration. Apply the base and WhatsApp schemas in order:

```powershell
Get-Content .\database\migrations\001_initial_schema.sql |
    mysql -u root -p
Get-Content .\database\migrations\002_whatsapp_gateway_schema.sql |
    mysql -u root -p talsinet_hub
```

Fill the WhatsApp and application-key placeholders in the root `.env` file
using `.env.example`. Use unique, high-entropy API keys for
`SECUREPHONE_API_KEY`, `CIVICPAY_API_KEY`, and `FIRMCASE_API_KEY`. The
provisioner requires each key to be at least 32 characters.
provisioning command stores password hashes for application keys in the
existing `applications` table; it does not print the keys. Never commit `.env`
or put credentials in source control. The older `WHATSAPP_META_*` names for
app secret, verify token, Graph version, and access token remain accepted for
compatibility with an existing `.env`.

In Meta, configure the WhatsApp product, connect the WABA, register the three
phone numbers, and issue a managed system-user token with the required
WhatsApp messaging and business-management permissions. The phone-number IDs
must belong to the configured WABA. Use the same Meta App Secret and verify
token in this service and configure the callback URL
`https://hub.talsinet.digital/webhooks/meta` with the `messages` webhook field.
Choose a supported Graph API version and set the matching `META_GRAPH_API_VERSION`.

Once Meta and the database are configured, provision the application accounts
and channels. This verifies the configured phone-number IDs against the WABA
before enabling the channels:

```powershell
php .\bin\provision-whatsapp.php
```

The channel mapping is `SECUREPHONE_WA` → SecurePhone,
`CIVICPAY_WA` → CivicPay AI, and `FIRMCASE_WA` → FirmCase. Each application
must authenticate with its own API key and application code. For example:

```http
X-Application-Code: SECUREPHONE
X-API-Key: <SecurePhone API key>
Content-Type: application/json
```

Send a text message with `POST /api/whatsapp/messages`:

```json
{
  "channel": "SECUREPHONE_WA",
  "to": "26876123456",
  "text": "Your message"
}
```

Applications can read their own inbound messages with
`GET /api/whatsapp/messages?after_id=0&limit=50`, using the same two headers.
The `after_id` cursor is the Hub message ID returned in each inbox item; each
application is scoped to its own channels.

Meta webhook POSTs are signature-checked against the raw body and saved before
being acknowledged. Process queued and failed webhook events regularly so
messages and status updates are retried after transient database or processing
errors. For example, run this command every minute from Task Scheduler:

```powershell
php .\bin\process-whatsapp-webhooks.php
```

Incoming messages are available in the owning application's inbox after the
worker processes the event. SecurePhone-channel messages of type `text`,
`image`, and `document` are also posted individually to
`SECUREPHONE_API_BASE_URL/api/v1/integrations/talsinet/whatsapp/messages` with
`Authorization: Bearer TALSINET_API_KEY`. Meta's `document` type is sent as
`documents`, as required by the SecurePhone integration. SecurePhone
deduplicates retries using the original Meta message ID. Other message types
remain stored in the Hub inbox but are not forwarded to SecurePhone.

The Hub readiness endpoint `GET /ready` checks its database and queries
`SECUREPHONE_API_BASE_URL/api/v1/integrations/talsinet` with the same bearer
credential. It relays SecurePhone's readiness response and reports HTTP 503 if
the database or SecurePhone endpoint is unavailable. Set
`SECUREPHONE_API_BASE_URL=https://track.revcon.digital` and populate
`TALSINET_API_KEY` in the local `.env`; do not commit the key.

Sent, delivered, read, and failed Meta statuses are stored in
`whatsapp_message_statuses`.
