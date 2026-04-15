# PicDrop Photo Gallery

A web-based photo gallery and event management system built with PHP and MySQL/MariaDB. This project allows users to register, log in, upload and view images, manage events, and participate in leaderboards. It also includes an admin interface and email notifications using PHPMailer.

## Features

- **User Registration & Authentication**: Secure user registration, login, and verification.
- **Photo Gallery**: Upload, view, and download images. Gallery and slideshow views available.
- **Event Management**: Admins can create and manage events.
- **Leaderboard**: Track and display top users or event participants.
- **Admin Panel**: Manage users, events, and gallery content.
- **Email Notifications**: Uses PHPMailer for sending emails (e.g., verification, notifications).
- **Download as ZIP**: Download selected images as a ZIP archive.
- **Configurable via Docker**: Includes Docker and Docker Compose setup for easy deployment.

## Project Structure

```
├── docker-compose.yml         # Docker Compose configuration
├── Dockerfile                 # Dockerfile for PHP/Apache
├── uploads.ini                # PHP upload settings
├── db/
│   └── init.sql               # Database initialization script
└── src/
    ├── admin.php              # Admin interface
    ├── auth.php               # Authentication logic
    ├── config.php             # Configuration (DB, settings)
    ├── db.php                 # Database connection
    ├── download_zip.php       # Download images as ZIP
    ├── gallery.php            # Gallery display logic
    ├── get_images.php         # Fetch images for gallery
    ├── header.php             # Common header for pages
    ├── index.php              # Main landing page
    ├── info.php               # Info/about page
    ├── leaderboard.php        # Leaderboard logic
    ├── login.php              # Login page
    ├── logout.php             # Logout logic
    ├── mail_helper.php        # Email sending helper
    ├── manage_event.php       # Event management
    ├── register.php           # Registration page
    ├── slideshow.php          # Slideshow view
    ├── verify.php             # Email verification
    └── libs/
        └── PHPMailer/         # PHPMailer library (bundled)
```

## Getting Started

### Prerequisites
- [Docker](https://www.docker.com/get-started)
- [Docker Compose](https://docs.docker.com/compose/)

### Setup & Run

1. **Clone the repository:**
   ```sh
   git clone <repo-url>
   cd picdrop
   ```
2. **Configure Environment:**
   - Edit `src/config.php` for database and site settings if needed.
   - Adjust `uploads.ini` for upload limits if required.
3. **Start the application:**
   ```sh
   docker-compose up --build
   ```
4. **Access the app:**
   - Open [http://localhost:8080](http://localhost:8080) in your browser.
   - Consider using reverse proxies such as Traefik (as used in the provided `docker-compose.yml`), nginx or the likes in production

### Database
- The database is initialized using `db/init.sql` on first run.
- Default credentials and settings can be changed in `.env` (see `example.env`), `src/config.php` and `db/init.sql`.

## Email Setup
- PHPMailer is included in `src/libs/PHPMailer/`.
- Configure SMTP settings in `src/mail_helper.php` and/or `src/config.php`.
- Ensure your SMTP credentials are correct for email features to work.

## Customization
- **Events**: Use the admin panel to create and manage events.
- **Gallery**: Upload and manage images via the web interface.
- **Styling**: Customize the UI by editing CSS in the relevant PHP files or adding your own stylesheets.

## Security Notes
- Change default admin credentials after setup.
- Use strong passwords for all users.
- Consider enabling HTTPS in production.

## Troubleshooting
- Check Docker logs for errors: `docker-compose logs`
- Ensure database container is running and accessible.
- Verify SMTP settings for email functionality.

## License
Brought to you by [Klimarschanlage Vertrieb Ltd](https://klimarschanlage.de). Contact our [team via mail](mailto:vertrieb@klimarschanlage.de) for licensing information, help or to thank them for their incredible work.

## Credits
- [PHPMailer](https://github.com/PHPMailer/PHPMailer) for email functionality.