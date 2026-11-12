# Deployment guide — VM, local IP and subdomain

This guide takes you from an empty VM to `https://attendance.cnr.edu.bt` (use whatever subdomain you prefer) that is reachable only inside the college network.

Replace these example values everywhere:

| Example | Meaning |
|---|---|
| `10.10.5.20` | The VM's local (LAN) IP address |
| `attendance.cnr.edu.bt` | Your chosen subdomain |
| `10.10.0.0/16` | Your staff LAN / Wi-Fi network range |

---

## 1. Create the VM

1. Create a VM with **Ubuntu Server 24.04 LTS** (1 vCPU, 2 GB RAM, 20 GB disk is plenty).
2. **Network mode must be Bridged** (VirtualBox/VMware) or attached to the college LAN bridge (Proxmox/Hyper-V external switch). With NAT mode the VM gets a hidden IP and other computers cannot reach it.
3. Give the VM a **fixed local IP**. Either:
   - set a **DHCP reservation** for the VM's MAC address on your router/DHCP server (recommended), or
   - set a static IP in `/etc/netplan/*.yaml`.
4. Set the time zone and keep the clock accurate (attendance times come from the server clock):

```bash
sudo timedatectl set-timezone Asia/Thimphu
sudo timedatectl set-ntp true
timedatectl            # "System clock synchronized: yes"
```

## 2. Install the web server, PHP and MySQL

```bash
sudo apt update
sudo apt install -y apache2 mariadb-server php libapache2-mod-php php-mysql php-mbstring git
sudo mysql_secure_installation
```

## 3. Get the code

```bash
cd /var/www
sudo git clone https://github.com/bmlimboo/staff-attendance-system.git
sudo chown -R root:www-data staff-attendance-system
```

## 4. Create the database

```bash
cd /var/www/staff-attendance-system
sudo mysql < database/schema.sql
sudo mysql -e "CREATE USER 'attendance_user'@'localhost' IDENTIFIED BY 'PUT-A-STRONG-PASSWORD-HERE';
               GRANT ALL PRIVILEGES ON staff_attendance.* TO 'attendance_user'@'localhost';
               FLUSH PRIVILEGES;"
```

## 5. Configure the app

```bash
sudo cp app/config.sample.php app/config.php
sudo nano app/config.php                      # put the same password in 'pass'
sudo chown root:www-data app/config.php
sudo chmod 640 app/config.php
```

Create the first System Administrator:

```bash
sudo php bin/create_admin.php
```

## 6. Apache virtual host

```bash
sudo nano /etc/apache2/sites-available/attendance.conf
```

```apache
<VirtualHost *:80>
    ServerName attendance.cnr.edu.bt
    DocumentRoot /var/www/staff-attendance-system/public

    <Directory /var/www/staff-attendance-system/public>
        Options -Indexes
        AllowOverride None
        Require all granted
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/attendance_error.log
    CustomLog ${APACHE_LOG_DIR}/attendance_access.log combined
</VirtualHost>
```

```bash
sudo a2ensite attendance
sudo a2dissite 000-default
sudo systemctl reload apache2
sudo ufw allow 80,443/tcp        # only if ufw is enabled
```

Only the `public/` folder is served. `app/`, `database/` and `bin/` are outside the web root, so passwords and source code are not downloadable.

## 7. Make the subdomain point to the local IP (split DNS)

You have a **local DNS** and a **global DNS**. For an internal-only system the right setup is:

- **Local DNS:** add the record `attendance.cnr.edu.bt → 10.10.5.20`.
- **Global DNS:** add **nothing**. Outside the college the name simply does not exist, which is a good extra layer of protection. (A public record pointing to a private IP is not needed, and does nothing useful.)

Add the record on whichever local DNS you run:

**Windows Server DNS**
DNS Manager → Forward Lookup Zones → `cnr.edu.bt` (if the zone does not exist locally, create a primary zone with that name) → right-click → *New Host (A or AAAA)* → Name `attendance`, IP `10.10.5.20`.

**BIND**
Add to the zone file, then increase the serial and run `sudo rndc reload`:
```
attendance    IN    A    10.10.5.20
```

**Pi-hole / dnsmasq**
```
address=/attendance.cnr.edu.bt/10.10.5.20
```

**MikroTik**
```
/ip dns static add name=attendance.cnr.edu.bt address=10.10.5.20
```

**Check it** from a staff computer:

```bash
nslookup attendance.cnr.edu.bt          # should answer 10.10.5.20
ping attendance.cnr.edu.bt
```

Common problems:

- Staff devices must use the **local DNS server** (set it in DHCP). A phone or laptop using `8.8.8.8` or "Private DNS / DNS-over-HTTPS" will not find the name.
- If `cnr.edu.bt` is hosted at an outside provider and your local DNS only forwards queries, create a local zone or a *conditional/override* entry for just `attendance.cnr.edu.bt`.
- Test in a browser: `http://attendance.cnr.edu.bt` should show the sign-in page, and it will display **"Your IP address"**.

## 8. HTTPS (strongly recommended)

Staff passwords and the device cookie travel over the network, so use HTTPS even on the LAN. Two options:

### Option A — Let's Encrypt using DNS validation (trusted by all devices)

This works even though the server has a private IP, but you must be able to add a **TXT record in the global DNS** for `cnr.edu.bt` (ask whoever manages it).

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot certonly --manual --preferred-challenges dns -d attendance.cnr.edu.bt
# certbot shows a TXT record to add as _acme-challenge.attendance.cnr.edu.bt, then press Enter
```

Manual certificates must be renewed every 90 days. If your DNS provider has an API, use the matching certbot DNS plugin so renewal is automatic.

### Option B — Self-signed / college CA certificate

Free and simple, but every phone and laptop shows a warning unless the certificate is installed on it.

```bash
sudo openssl req -x509 -nodes -days 825 -newkey rsa:2048 \
  -keyout /etc/ssl/private/attendance.key -out /etc/ssl/certs/attendance.crt \
  -subj "/CN=attendance.cnr.edu.bt" -addext "subjectAltName=DNS:attendance.cnr.edu.bt"
```

### Enable HTTPS in Apache (either option)

```bash
sudo a2enmod ssl headers rewrite
sudo nano /etc/apache2/sites-available/attendance.conf
```

Replace the file with:

```apache
<VirtualHost *:80>
    ServerName attendance.cnr.edu.bt
    RewriteEngine On
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
</VirtualHost>

<VirtualHost *:443>
    ServerName attendance.cnr.edu.bt
    DocumentRoot /var/www/staff-attendance-system/public

    SSLEngine on
    # Option A paths:
    SSLCertificateFile    /etc/letsencrypt/live/attendance.cnr.edu.bt/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/attendance.cnr.edu.bt/privkey.pem
    # Option B paths: /etc/ssl/certs/attendance.crt  and  /etc/ssl/private/attendance.key

    Header always set Strict-Transport-Security "max-age=31536000"

    <Directory /var/www/staff-attendance-system/public>
        Options -Indexes
        AllowOverride None
        Require all granted
    </Directory>
</VirtualHost>
```

```bash
sudo apache2ctl configtest && sudo systemctl reload apache2
```

## 9. Tell the system which networks are "the office"

Sign in at `https://attendance.cnr.edu.bt` as the administrator → **Settings → Allowed networks**.

Replace the defaults with your real staff LAN and Wi-Fi ranges, one per line, e.g.:

```
10.10.0.0/16
10.20.5.0/24
```

Important: the system sees the IP address of whoever connects. If traffic from staff Wi-Fi reaches the server through a router that **translates (NATs) addresses**, every staff device will look like the router's IP. Open the login page from a staff phone on Wi-Fi and from a LAN laptop and check that **"Your IP address"** shows different, real addresses. If it shows the same gateway IP for everyone, either allow that single gateway IP or ask network staff to route (not NAT) traffic to the VM.

## 10. First-time setup order

1. **Settings** — confirm networks, working hours and grace period.
2. **Departments** — add each department (give each a short code).
3. **Staff & Users** — add people, or import a CSV. Assign roles (HoD must have a department).
4. **College Calendar** — import the college holiday calendar (`.ics` or `.csv`).
5. Ask staff to sign in from the college network, then register their laptop/phone under **My Devices**.
6. **Device Approvals** — approve each device once.

## 11. Backups and updates

Nightly database backup (run `sudo crontab -e`):

```
0 2 * * * mysqldump staff_attendance | gzip > /var/backups/staff_attendance_$(date +\%F).sql.gz
```

Copy the backups to another machine regularly. To update the app later:

```bash
cd /var/www/staff-attendance-system && sudo git pull
```
