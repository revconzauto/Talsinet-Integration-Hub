# Talsinet Integration Hub

## Local database connection

The local application connects to the MariaDB database `talsinet_hub` using PDO
and the MySQL PDO driver. By default, it uses `127.0.0.1:3306` and the local
`root` account with an empty password, as in a default XAMPP installation.

To use different connection settings, set these environment variables in the
PowerShell window where you start the PHP server:

```powershell
$env:DB_HOST = "127.0.0.1"
$env:DB_PORT = "3306"
$env:DB_DATABASE = "talsinet_hub"
$env:DB_USERNAME = "root"
$env:DB_PASSWORD = "your MariaDB password"
php -S localhost:8000 -t public
```

If the server is already running, stop it with `Ctrl+C`, set the variables, and
start it again. The `/` endpoint reports whether the database connection
succeeded. Connection failures are logged to the PHP server console; the
response does not expose credentials or internal database errors.
